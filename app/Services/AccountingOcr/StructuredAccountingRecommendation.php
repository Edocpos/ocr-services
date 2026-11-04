<?php

namespace App\Services\AccountingOcr;

use App\Contracts\Ocr\StructuredAccountingClient;
use RuntimeException;

class StructuredAccountingRecommendation
{
    public function __construct(private readonly StructuredAccountingClient $client) {}

    public function recommend(array $input): array
    {
        $kind = $input['document_kind'];
        $document = $input['document'];
        $commercial = in_array($kind, ['customer_invoice', 'customer_credit_note', 'customer_debit_note', 'supplier_bill', 'supplier_credit_note', 'supplier_debit_note'], true);
        $authority = $this->cents($document[$commercial ? 'total' : 'amount']);
        if ($authority <= 0) {
            throw new RuntimeException('structured_accounting_invalid_amount');
        }
        $proposal = $this->client->recommend($input);
        $accountMap = [];
        $registry = new AccountCodeRegistry;
        foreach ($input['accounts'] as $account) {
            $accountMap[$account['code']] = $account;
        }
        $lines = [];
        $debitTotal = 0;
        $creditTotal = 0;
        foreach ($proposal['lines'] ?? [] as $line) {
            if (! is_array($line)) {
                throw new RuntimeException('structured_accounting_invalid_proposal');
            }
            $code = $line['account_code'] ?? null;
            if ($code !== null && (! is_string($code) || ! isset($accountMap[$code]))) {
                throw new RuntimeException('structured_accounting_invalid_account');
            }
            $requiredType = $line['required_account_type'] ?? null;
            $requiredRole = $line['required_role'] ?? null;
            if (! in_array($requiredType, ['asset', 'liability', 'equity', 'revenue', 'expense'], true)
                || ! in_array($requiredRole, $registry->roles(), true)) {
                throw new RuntimeException('structured_accounting_invalid_requirement');
            }
            $knownPrefix = $code === null ? null : $registry->accountPrefix($accountMap[$code]);
            if ($code !== null && ($accountMap[$code]['type'] !== $requiredType
                || ($knownPrefix !== null && $registry->accountRole($accountMap[$code]) !== $requiredRole))) {
                throw new RuntimeException('structured_accounting_invalid_account');
            }
            if ($code !== null && in_array($requiredRole, ['trade_receivable', 'trade_payable'], true)) {
                $names = [$accountMap[$code]['name'], ...($accountMap[$code]['aliases'] ?? [])];
                $party = $this->normalizeName($input['counterparty']['name']);
                $generic = $requiredRole === 'trade_receivable'
                    ? ['TRADE RECEIVABLES', 'TRADE DEBTORS', 'ACCOUNTS RECEIVABLE', 'AR']
                    : ['TRADE PAYABLES', 'TRADE CREDITORS', 'ACCOUNTS PAYABLE', 'AP'];
                if (! in_array($party, array_map($this->normalizeName(...), $names), true)
                    && ! array_intersect($generic, array_map($this->normalizeName(...), $names))) {
                    throw new RuntimeException('structured_accounting_counterparty_mismatch');
                }
            }
            $debit = $this->cents($line['debit'] ?? null);
            $credit = $this->cents($line['credit'] ?? null);
            if ($debit < 0 || $credit < 0 || ($debit > 0) === ($credit > 0)) {
                throw new RuntimeException('structured_accounting_invalid_amount');
            }
            $debitTotal += $debit;
            $creditTotal += $credit;
            $recommendation = null;
            if ($code === null) {
                $candidate = $line['recommendation'] ?? null;
                if (! is_array($candidate)) {
                    throw new RuntimeException('structured_accounting_missing_recommendation');
                }
                foreach (['suggested_name', 'parent_code', 'account_type', 'account_subtype'] as $field) {
                    if (! is_string($candidate[$field] ?? null) || trim($candidate[$field]) === '') {
                        throw new RuntimeException('structured_accounting_missing_recommendation');
                    }
                }
                if ($candidate['account_type'] !== $requiredType) {
                    throw new RuntimeException('structured_accounting_invalid_recommendation');
                }
                $recommendation = [
                    'suggested_name' => trim($candidate['suggested_name']),
                    'parent_code' => trim($candidate['parent_code']),
                    'parent_name' => $this->nullableText($candidate['parent_name'] ?? null),
                    'parent_definition_key' => $this->nullableText($candidate['parent_definition_key'] ?? null),
                    'account_type' => $candidate['account_type'],
                    'account_subtype' => $candidate['account_subtype'],
                    'reason' => $this->nullableText($candidate['reason'] ?? null),
                ];
            }
            $confidence = $line['confidence'] ?? null;
            $lines[] = [
                'account_code' => $code,
                'account_name' => $code === null ? null : $accountMap[$code]['name'],
                'debit' => $debit / 100,
                'credit' => $credit / 100,
                'match_status' => $code === null ? 'unmatched' : 'matched',
                'confidence' => is_numeric($confidence) && (float) $confidence >= 0 && (float) $confidence <= 1 ? (float) $confidence : null,
                'reason' => $this->nullableText($line['reason'] ?? null),
                'evidence' => $this->nullableText($line['evidence'] ?? null),
                'description' => $this->nullableText($line['description'] ?? null),
                'recommendation' => $recommendation,
            ];
        }
        if ($lines === [] || $debitTotal !== $creditTotal || $debitTotal !== $authority) {
            throw new RuntimeException('structured_accounting_unbalanced_proposal');
        }
        $unmatched = in_array('unmatched', array_column($lines, 'match_status'), true);
        $lowConfidence = count(array_filter($lines, fn (array $line) => $line['confidence'] === null || $line['confidence'] < (float) config('ocr.accounting_confidence_min', 0.75))) > 0;
        $errors = $unmatched ? [[
            'code' => 'ACCOUNT_NOT_AVAILABLE',
            'field' => 'journal_entry.lines',
            'message' => 'One or more required accounts are not in the supplied chart of accounts.',
        ]] : [];
        $warnings = $lowConfidence ? [[
            'code' => 'low_confidence',
            'field' => 'journal_entry.lines',
            'message' => 'One or more accounting lines require review because confidence is low or missing.',
        ]] : [];
        $date = $document[match ($kind) {
            'customer_receipt' => 'receipt_date',
            'supplier_payment' => 'payment_date',
            'customer_refund', 'supplier_refund' => 'refund_date',
            default => 'document_date',
        }];
        $extracted = [
            'transaction_date' => $date,
            'reference_number' => $document['reference'] ?? null,
            'counterparty' => $input['counterparty']['name'],
            'description' => $document['description'] ?? null,
            'currency' => 'MYR',
        ];
        if ($commercial) {
            $extracted += [
                'subtotal' => $this->cents($document['subtotal']) / 100,
                'tax' => $this->cents($document['tax']) / 100,
                'total' => $authority / 100,
            ];
        } else {
            $extracted['amount'] = $authority / 100;
        }
        $extracted['source_document_kind'] = $kind;
        $voucher = match ($kind) {
            'customer_receipt', 'supplier_refund' => 'receipt_voucher',
            'customer_refund', 'supplier_payment' => 'payment_voucher',
            default => 'journal_voucher',
        };

        return [
            'data' => [
                'extracted' => $extracted,
                'classification' => ['document_kind' => $kind, 'voucher_type' => $voucher],
                'journal_entry' => [
                    'lines' => $lines,
                    'total_debit' => $debitTotal / 100,
                    'total_credit' => $creditTotal / 100,
                    'is_balanced' => true,
                ],
            ],
            'validation' => [
                'status' => $unmatched || $lowConfidence ? 'review_required' : 'ok',
                'errors' => $errors,
                'warnings' => $warnings,
                'is_postable' => ! $unmatched && ! $lowConfidence,
                'requires_manual_review' => $unmatched || $lowConfidence,
            ],
            'meta' => [
                'provider' => 'gemini',
                'document_type' => 'accounting_structured',
                'stateless' => true,
                'input_mode' => 'structured',
            ],
        ];
    }

    private function cents(mixed $amount): int
    {
        if (! is_numeric($amount) || ! is_finite((float) $amount) || (float) $amount < 0 || (float) $amount > 999999999999) {
            throw new RuntimeException('structured_accounting_invalid_amount');
        }
        $value = (float) $amount * 100;
        if (abs($value - round($value)) > 0.05) {
            throw new RuntimeException('structured_accounting_invalid_amount');
        }

        return (int) round($value);
    }

    private function nullableText(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function normalizeName(string $name): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtoupper($name)) ?? '');
    }
}
