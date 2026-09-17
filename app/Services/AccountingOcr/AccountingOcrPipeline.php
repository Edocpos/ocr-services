<?php

namespace App\Services\AccountingOcr;

use App\Contracts\Ocr\AccountingOcrClient;
use App\Exceptions\AccountingOcrException;
use App\Services\IcOcr\OcrUsageEstimator;
use App\Support\OcrDocumentMime;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class AccountingOcrPipeline
{
    public function __construct(
        private readonly AccountingOcrClient $client,
        private readonly AccountRecommendationService $recommendations,
        private readonly OcrUsageEstimator $usageEstimator,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $accounts
     * @return array<string, mixed>
     */
    public function process(UploadedFile $document, array $accounts, string $requestId): array
    {
        $path = $document->getRealPath();
        $content = $path === false ? false : file_get_contents($path);
        if (! is_string($content) || $content === '') {
            throw AccountingOcrException::unreadable();
        }

        $mime = OcrDocumentMime::identify($content);
        if ($mime === null) {
            throw AccountingOcrException::unreadable();
        }

        Log::info('accounting_ocr_input_diagnostics', [
            'request_id' => $requestId,
            'document_sha256' => hash('sha256', $content),
            'mime' => $mime,
            'bytes' => strlen($content),
            'account_count' => count($accounts),
        ]);

        $payload = $this->client->extract($content, $mime, $accounts);
        $transactionCount = max(0, (int) ($payload['transaction_count'] ?? 0));
        if ($transactionCount > 1) {
            throw AccountingOcrException::multipleTransactions();
        }
        if ($transactionCount === 0 || ! is_array($payload['lines'] ?? null) || $payload['lines'] === []) {
            throw AccountingOcrException::unreadable();
        }

        $accountMap = [];
        foreach ($accounts as $account) {
            $accountMap[strtoupper(trim((string) $account['code']))] = $account;
        }
        $allocationAccounts = $accounts;

        $errors = [];
        $warnings = [];
        $journalLines = [];
        $hasRecommendation = false;
        $hasUnmatched = false;
        $cashDebit = 0.0;
        $cashCredit = 0.0;
        $hasTaxLine = false;

        foreach (array_values($payload['lines']) as $index => $sourceLine) {
            if (! is_array($sourceLine)) {
                $sourceLine = [];
            }

            if ((is_numeric($sourceLine['debit'] ?? null) && (float) $sourceLine['debit'] < 0)
                || (is_numeric($sourceLine['credit'] ?? null) && (float) $sourceLine['credit'] < 0)) {
                $errors[] = $this->issue('journal_entry.lines.'.($index + 1), 'negative_line_amount', 'Journal line amounts cannot be negative.');
            }

            $debit = $this->amount($sourceLine['debit'] ?? 0);
            $credit = $this->amount($sourceLine['credit'] ?? 0);
            if (($debit > 0 && $credit > 0) || ($debit === 0.0 && $credit === 0.0)) {
                $errors[] = $this->issue('journal_entry.lines.'.($index + 1), 'invalid_line_amount', 'Each journal line must contain a positive debit or credit, but not both.');
            }
            $lineConfidence = $this->confidence($sourceLine['confidence'] ?? null);
            if ($lineConfidence < (float) config('ocr.accounting_confidence_min', 0.75)) {
                $warnings[] = $this->issue('journal_entry.lines.'.($index + 1), 'low_account_match_confidence', 'The account treatment confidence is below the configured threshold.');
            }
            if (trim((string) ($sourceLine['reason'] ?? '')) === '' || trim((string) ($sourceLine['evidence'] ?? '')) === '') {
                $warnings[] = $this->issue('journal_entry.lines.'.($index + 1), 'missing_audit_support', 'The journal line is missing a posting reason or supporting document evidence.');
            }

            $selected = strtoupper(trim((string) ($sourceLine['selected_account_code'] ?? '')));
            $matched = $selected !== '' && isset($accountMap[$selected]) ? $accountMap[$selected] : null;
            $recommendation = null;
            $subtype = null;
            $matchStatus = 'matched';

            if ($matched !== null) {
                $subtype = (string) $matched['subtype'];
            } else {
                if ($selected !== '') {
                    $warnings[] = $this->issue('journal_entry.lines.'.($index + 1), 'invalid_selected_account', 'The provider selected an account code that was not supplied; it was rejected.');
                }

                $recommendation = $this->recommendations->recommend($sourceLine, $allocationAccounts);
                if ($recommendation !== null) {
                    $hasRecommendation = true;
                    $matchStatus = 'new_account_recommended';
                    $subtype = (string) $recommendation['account_subtype'];
                    if ($recommendation['provisional_code'] === null) {
                        $warnings[] = $this->issue('journal_entry.lines.'.($index + 1), 'account_code_range_exhausted', 'The user-defined number range for the recommended account category is exhausted.');
                    } else {
                        $allocationAccounts[] = [
                            'code' => $recommendation['provisional_code'],
                            'name' => $recommendation['suggested_name'],
                        ];
                    }
                } else {
                    $hasUnmatched = true;
                    $matchStatus = 'unmatched';
                    $warnings[] = $this->issue('journal_entry.lines.'.($index + 1), 'account_unmatched', 'No suitable submitted account or valid account-code recommendation was available.');
                }
            }

            if (in_array($subtype, ['cash', 'bank'], true)) {
                $cashDebit += $debit;
                $cashCredit += $credit;
            }
            if (in_array($subtype, ['input_tax', 'output_tax'], true)) {
                $hasTaxLine = true;
            }

            $journalLines[] = [
                'account_code' => $matched['code'] ?? null,
                'account_name' => $matched['name'] ?? null,
                'debit' => $debit,
                'credit' => $credit,
                'match_status' => $matchStatus,
                'confidence' => $lineConfidence,
                'reason' => trim((string) ($sourceLine['reason'] ?? '')),
                'evidence' => trim((string) ($sourceLine['evidence'] ?? '')),
                'recommendation' => $recommendation,
            ];
        }

        $totalDebit = round(array_sum(array_column($journalLines, 'debit')), 2);
        $totalCredit = round(array_sum(array_column($journalLines, 'credit')), 2);
        $isBalanced = $totalDebit > 0 && abs($totalDebit - $totalCredit) <= 0.01;
        if (! $isBalanced) {
            $errors[] = $this->issue('journal_entry', 'unbalanced_entry', 'Total debits must equal total credits within 0.01.');
        }

        $extracted = $this->extracted($payload);
        foreach (['subtotal', 'tax', 'total'] as $amountField) {
            if (is_numeric($payload[$amountField] ?? null) && (float) $payload[$amountField] < 0) {
                $errors[] = $this->issue($amountField, 'negative_document_amount', 'Extracted document amounts cannot be negative.');
            }
        }
        $this->validateDocumentTotals($extracted, $totalDebit, $errors);

        $currency = $extracted['currency'];
        if ($currency !== 'MYR') {
            $warnings[] = $this->issue('currency', 'foreign_currency_requires_review', 'Foreign currency was preserved without calculating a base-currency exchange rate.');
        }
        if (($extracted['tax'] ?? 0) > 0 && ! $hasTaxLine) {
            $warnings[] = $this->issue('tax', 'tax_account_missing', 'Explicit tax was detected, but no tax account line was matched or recommended.');
        }

        $cashNet = round($cashDebit - $cashCredit, 2);
        $isTransfer = $cashDebit > 0 && $cashCredit > 0 && abs($cashNet) <= 0.01;
        $voucherType = match (true) {
            $isTransfer => 'general_voucher',
            $cashNet > 0.01 => 'receipt_voucher',
            $cashNet < -0.01 => 'payment_voucher',
            default => 'general_voucher',
        };
        if ($isTransfer) {
            $warnings[] = $this->issue('voucher_type', 'cash_bank_transfer', 'The entry transfers value between cash or bank accounts and is classified as a general voucher.');
        }
        if ($cashDebit === 0.0 && $cashCredit === 0.0 && ($extracted['payment_method'] !== null || $extracted['payment_reference'] !== null)) {
            $warnings[] = $this->issue('voucher_type', 'cash_direction_unclear', 'Payment details were detected without a matched or recommended cash/bank account.');
        }

        $overallConfidence = $this->confidence($payload['overall_confidence'] ?? null);
        if ($overallConfidence < (float) config('ocr.accounting_confidence_min', 0.75)) {
            $warnings[] = $this->issue('document', 'low_confidence', 'The accounting extraction confidence is below the configured threshold.');
        }

        $requiresReview = $hasRecommendation || $hasUnmatched || $errors !== [] || $warnings !== [];
        $isPostable = $isBalanced && ! $requiresReview;
        $usage = $this->usageEstimator->estimate(1, is_array($payload['usage'] ?? null) ? $payload['usage'] : null, 'gemini');

        Log::info('accounting_ocr_processed', [
            'request_id' => $requestId,
            'voucher_type' => $voucherType,
            'line_count' => count($journalLines),
            'is_balanced' => $isBalanced,
            'is_postable' => $isPostable,
            'requires_manual_review' => $requiresReview,
            'overall_confidence' => $overallConfidence,
        ]);

        return [
            'data' => [
                'extracted' => $extracted,
                'classification' => ['voucher_type' => $voucherType],
                'journal_entry' => [
                    'lines' => $journalLines,
                    'total_debit' => $totalDebit,
                    'total_credit' => $totalCredit,
                    'is_balanced' => $isBalanced,
                ],
            ],
            'validation' => [
                'status' => $requiresReview ? 'review_required' : 'ok',
                'errors' => $errors,
                'warnings' => $warnings,
                'is_postable' => $isPostable,
                'requires_manual_review' => $requiresReview,
            ],
            'confidence' => ['overall' => $overallConfidence],
            'usage' => $usage,
            'meta' => [
                'provider' => 'gemini',
                'document_type' => 'accounting',
                'stateless' => true,
                'request_id' => $requestId,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function extracted(array $payload): array
    {
        $currency = strtoupper(trim((string) ($payload['currency'] ?? '')));

        return [
            'transaction_date' => $this->stringOrNull($payload['transaction_date'] ?? null),
            'reference_number' => $this->stringOrNull($payload['reference_number'] ?? null),
            'counterparty' => $this->stringOrNull($payload['counterparty'] ?? null),
            'description' => $this->stringOrNull($payload['description'] ?? null),
            'currency' => $currency !== '' ? $currency : 'MYR',
            'subtotal' => $this->nullableAmount($payload['subtotal'] ?? null),
            'tax' => $this->nullableAmount($payload['tax'] ?? null),
            'total' => $this->nullableAmount($payload['total'] ?? null),
            'payment_method' => $this->stringOrNull($payload['payment_method'] ?? null),
            'payment_reference' => $this->stringOrNull($payload['payment_reference'] ?? null),
        ];
    }

    /** @param list<array<string, string>> $errors */
    private function validateDocumentTotals(array $extracted, float $totalDebit, array &$errors): void
    {
        if ($extracted['subtotal'] !== null && $extracted['tax'] !== null && $extracted['total'] !== null
            && abs(($extracted['subtotal'] + $extracted['tax']) - $extracted['total']) > 0.01) {
            $errors[] = $this->issue('total', 'conflicting_document_totals', 'The extracted subtotal and tax do not equal the extracted total.');
        }
        if ($extracted['total'] !== null && abs($totalDebit - $extracted['total']) > 0.01) {
            $errors[] = $this->issue('journal_entry', 'transaction_total_mismatch', 'The journal total does not equal the extracted document total.');
        }
    }

    /** @return array{field:string,code:string,message:string} */
    private function issue(string $field, string $code, string $message): array
    {
        return compact('field', 'code', 'message');
    }

    private function amount(mixed $value): float
    {
        return round(max(0, is_numeric($value) ? (float) $value : 0), 2);
    }

    private function nullableAmount(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value >= 0 ? $this->amount($value) : null;
    }

    private function confidence(mixed $value): float
    {
        return round(max(0, min(1, is_numeric($value) ? (float) $value : 0)), 4);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
