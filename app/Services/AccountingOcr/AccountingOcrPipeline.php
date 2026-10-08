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
        private readonly AccountRequirementMatcher $accountMatcher,
        private readonly AccountRecommendationService $accountRecommendations,
        private readonly OcrUsageEstimator $usageEstimator,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $accounts
     * @return array<string, mixed>
     */
    public function process(UploadedFile $document, array $accounts, ?string $companyContext, string $requestId, array $context = []): array
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

        $payload = $this->client->extract($content, $mime, $accounts, $companyContext, $context);
        $transactionCount = max(0, (int) ($payload['transaction_count'] ?? 0));
        if ($transactionCount > 1) {
            throw AccountingOcrException::multipleTransactions();
        }
        if ($transactionCount === 0 || ! is_array($payload['lines'] ?? null) || $payload['lines'] === []) {
            throw AccountingOcrException::unreadable();
        }

        $registry = new AccountCodeRegistry;
        $errors = [];
        $warnings = [];
        $journalLines = [];
        $hasUnmatched = false;
        $cashDebit = 0.0;
        $cashCredit = 0.0;
        $hasTaxLine = false;
        $classificationComplete = in_array($payload['document_kind'] ?? null, ['invoice', 'debit_note', 'credit_note', 'bill', 'receipt', 'insurance', 'other'], true)
            && in_array($payload['trade_nature'] ?? null, ['trade', 'non_trade', 'mixed'], true)
            && $this->stringOrNull($payload['economic_event'] ?? null) !== null
            && $this->stringOrNull($payload['treatment_summary'] ?? null) !== null;
        if (! $classificationComplete) {
            $warnings[] = $this->issue('classification', 'economic_classification_unclear', 'Document event or trade nature is unclear; Arkcloudant must review the treatment.');
        }
        if (in_array($payload['document_kind'] ?? null, ['invoice', 'debit_note', 'credit_note', 'bill', 'insurance'], true)
            && ($payload['payment_status'] ?? 'unknown') === 'unknown') {
            $warnings[] = $this->issue('payment_status', 'payment_status_unclear', 'Payment is not established by document terms or bank details; confirm settlement status before posting.');
        }

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

            $match = $this->accountMatcher->match($sourceLine, $accounts);
            $matched = $match['account'];
            $requiredAccount = $match['requirement'];
            if (($payload['trade_nature'] ?? null) === 'trade' && $requiredAccount['role'] === 'other_payable') {
                $matched = null;
                $match['error_code'] = 'ACCOUNT_TREATMENT_CONFLICT';
            }
            $guidance = $registry->guidance($requiredAccount['parent_code']);
            $subtype = $guidance['subtype'] ?? $requiredAccount['role'];
            $matchStatus = 'matched';
            if ($match['error_code'] !== null) {
                $hasUnmatched = true;
                $matchStatus = strtolower($match['error_code']);
                $errors[] = $this->issue('journal_entry.lines.'.($index + 1), $match['error_code'], match ($match['error_code']) {
                    'ACCOUNT_NOT_AVAILABLE' => 'No supplied account satisfies the required accounting classification and control role. Arkcloudant must resolve this requirement before posting.',
                    'ACCOUNT_SELECTION_INVALID' => 'The selected account does not satisfy the required role. Eligible accounts exist, but no account was substituted automatically.',
                    'ACCOUNT_TREATMENT_CONFLICT' => 'A normal trade obligation cannot be classified as OTHER CREDITORS; review the required trade payable control role.',
                    default => 'The provider did not supply a valid accounting requirement before selecting an account.',
                });
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
                'account_subtype' => $subtype,
                'required_account' => $requiredAccount,
                'eligible_account_codes' => $match['available_codes'],
                'error_code' => $match['error_code'],
                'account_guidance' => $guidance,
                'account_purpose' => $matched['purpose'] ?? null,
                'account_usage_examples' => $matched['usage_examples'] ?? [],
                'confidence' => $lineConfidence,
                'reason' => trim((string) ($sourceLine['reason'] ?? '')),
                'evidence' => trim((string) ($sourceLine['evidence'] ?? '')),
                'recommendation' => $match['error_code'] === 'ACCOUNT_NOT_AVAILABLE'
                    ? $this->accountRecommendations->recommend($sourceLine, $requiredAccount)
                    : null,
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
            $warnings[] = $this->issue('tax', 'tax_account_missing', 'Explicit tax was detected, but no tax account line was matched.');
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
            $warnings[] = $this->issue('voucher_type', 'cash_direction_unclear', 'Payment details were detected without a matched cash/bank account.');
        }

        $overallConfidence = $this->confidence($payload['overall_confidence'] ?? null);
        if ($overallConfidence < (float) config('ocr.accounting_confidence_min', 0.75)) {
            $warnings[] = $this->issue('document', 'low_confidence', 'The accounting extraction confidence is below the configured threshold.');
        }

        $prepayment = (new PrepaymentAssessment)->assess($payload, $journalLines, $context, $warnings);

        $requiresReview = $hasUnmatched || $errors !== [] || $warnings !== [];
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
                'classification' => [
                    'voucher_type' => $voucherType,
                    'document_kind' => $payload['document_kind'] ?? 'unknown',
                    'economic_event' => $this->stringOrNull($payload['economic_event'] ?? null),
                    'trade_nature' => $payload['trade_nature'] ?? 'unknown',
                    'treatment_summary' => $this->stringOrNull($payload['treatment_summary'] ?? null),
                ],
                'business_context' => $context,
                'prepayment_assessment' => $prepayment,
                'journal_entry' => [
                    'lines' => $journalLines,
                    'total_debit' => $totalDebit,
                    'total_credit' => $totalCredit,
                    'is_balanced' => $isBalanced,
                    'is_complete' => ! $hasUnmatched,
                ],
            ],
            'validation' => [
                'status' => $requiresReview ? 'review_required' : 'ok',
                'errors' => $errors,
                'warnings' => $warnings,
                'is_postable' => $isPostable,
                'final_validation_required_by' => 'Arkcloudant',
                'requires_manual_review' => $requiresReview,
            ],
            'confidence' => ['overall' => $overallConfidence],
            'usage' => $usage,
            'meta' => [
                'provider' => 'gemini',
                'posting_authority' => 'Arkcloudant',
                'document_type' => 'accounting',
                'stateless' => true,
                'account_library_version' => $registry->library()['version'],
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
            'invoice_date' => $this->stringOrNull($payload['invoice_date'] ?? null),
            'event_date' => $this->stringOrNull($payload['event_date'] ?? null),
            'payment_date' => $this->stringOrNull($payload['payment_date'] ?? null),
            'original_document_reference' => $this->stringOrNull($payload['original_document_reference'] ?? null),
            'reference_number' => $this->stringOrNull($payload['reference_number'] ?? null),
            'counterparty' => $this->stringOrNull($payload['counterparty'] ?? null),
            'description' => $this->stringOrNull($payload['description'] ?? null),
            'document_direction' => $this->documentDirection($payload['document_direction'] ?? null),
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

    private function documentDirection(mixed $value): string
    {
        $direction = strtolower(trim((string) $value));

        return in_array($direction, ['incoming', 'outgoing', 'internal', 'unknown'], true)
            ? $direction
            : 'unknown';
    }
}
