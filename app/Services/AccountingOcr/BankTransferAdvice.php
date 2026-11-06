<?php

namespace App\Services\AccountingOcr;

use App\Services\StatutoryOcr\PdfEmbeddedTextExtractor;
use DateTimeImmutable;

class BankTransferAdvice
{
    /** Use printed transfer roles when a searchable bank approval contains all key facts. */
    public function normalize(array $payload, string $content, string $mime, array $accounts, ?string $companyContext): array
    {
        if ($mime !== 'application/pdf') {
            return $payload;
        }
        $text = (new PdfEmbeddedTextExtractor)->extract($content);
        if (! is_string($text) || ! str_contains($text, 'Transaction Approval')
            || substr_count($text, 'From Account') !== 1 || substr_count($text, 'Beneficiary Account') !== 1
            || substr_count($text, 'Approval Status') !== 1) {
            return $payload;
        }
        $pattern = '/^Success\R\d+\s*\/\s*(?<payer>[^\r\n]+)\R[^\r\n]+\R[^\r\n]+\R\d+\s*\/\s*(?<beneficiary>[^\r\n]+)\R(?<reference>[^\r\n]+)\R[^\r\n]+\RMYR\s*(?<amount>[\d,]+\.\d{2})\R(?<date>\d{1,2}-[A-Za-z]{3}-\d{4})/m';
        if (preg_match($pattern, $text, $match) !== 1) {
            return $payload;
        }
        $payer = trim($match['payer']);
        $beneficiary = trim($match['beneficiary'], " \t\n\r\0\x0B*");
        $date = DateTimeImmutable::createFromFormat('!d-M-Y', $match['date']);
        $company = is_string($companyContext) ? json_decode($companyContext, true) : null;
        $companyName = is_array($company) ? ($company['name'] ?? null) : null;
        if ($payer === '' || $beneficiary === '' || $date === false || ! is_string($companyName)) {
            return $payload;
        }
        $normalizeName = static fn (string $name): string => trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtoupper($name)) ?? '');
        $isBeneficiary = $normalizeName($companyName) === $normalizeName($beneficiary);
        $isPayer = $normalizeName($companyName) === $normalizeName($payer);
        $counterparty = $isBeneficiary !== $isPayer ? ($isBeneficiary ? $payer : $beneficiary) : null;
        $amount = (float) str_replace(',', '', $match['amount']);
        $reference = null;
        if (preg_match('/^Reference No\.\s*:\s*(\S+)/m', $text, $referenceMatch)) {
            $reference = $referenceMatch[1];
        }
        $bank = array_values(array_filter($accounts, fn (array $account): bool => (new AccountCodeRegistry)->accountRole($account) === 'bank'));
        $bankAccount = count($bank) === 1 ? $bank[0] : null;
        $role = $isBeneficiary ? 'trade_receivable' : 'trade_payable';
        $partyAccounts = array_values(array_filter($accounts, fn (array $account): bool => $counterparty !== null
            && (new AccountCodeRegistry)->accountRole($account) === $role
            && ($account['is_control_account'] ?? false) !== true
            && in_array($normalizeName($counterparty), array_map($normalizeName, [$account['name'], ...($account['aliases'] ?? [])]), true)));
        $partyAccount = count($partyAccounts) === 1 ? $partyAccounts[0] : null;
        $bankPrefix = $bankAccount === null ? 'BS/CA/CNB/BANK' : (new AccountCodeRegistry)->accountPrefix($bankAccount);
        $partyPrefix = $isBeneficiary ? 'BS/CA/TRV/TRDB' : 'BS/CL/TPY/TPTC';
        $line = static fn (?array $account, string $requiredRole, string $type, string $prefix, float $debit, float $credit, string $description, string $reason, string $evidence, ?string $suggestion = null): array => [
            'selected_account_code' => $account['code'] ?? null, 'suggested_account_name' => $account === null ? $suggestion : null,
            'required_role' => $requiredRole, 'required_account_type' => $type, 'required_prefix' => $prefix,
            'requires_control_account' => false, 'required_control_role' => null,
            'account_error' => $account === null ? 'ACCOUNT_NOT_AVAILABLE' : null,
            'debit' => $debit, 'credit' => $credit, 'confidence' => $account === null ? 0.6 : 0.95,
            'description' => $description, 'reason' => $reason, 'evidence' => $evidence,
        ];
        $bankLine = $line($bankAccount, 'bank', 'asset', $bankPrefix ?? 'BS/CA/CNB/BANK', $isBeneficiary ? $amount : 0, $isPayer ? $amount : 0,
            $isBeneficiary ? "Bank transfer received from $payer" : "Bank transfer paid to $beneficiary",
            $isBeneficiary ? 'Debit bank for confirmed funds received' : 'Credit bank for confirmed funds sent', "Amount MYR {$match['amount']}; Beneficiary Account $beneficiary", 'Bank account');
        if (count($bank) > 1) {
            $bankLine['account_error'] = null; // Ambiguous supplied banks need selection, not a new-account recommendation.
        }
        $partyLine = $line($partyAccount, $role, $isBeneficiary ? 'asset' : 'liability', $partyPrefix, $isPayer ? $amount : 0, $isBeneficiary ? $amount : 0,
            $isBeneficiary ? "Settlement of trade receivable from $payer" : "Settlement payable to $beneficiary",
            $isBeneficiary ? "Credit trade receivable for $payer" : "Debit trade payable for $beneficiary",
            $isBeneficiary ? "From Account $payer; Recipient Reference {$match['reference']}" : "Beneficiary Account $beneficiary; Recipient Reference {$match['reference']}", $counterparty);
        $payload = array_replace($payload, [
            'bank_transfer_advice' => true,
            'bank_transfer_payer' => $payer, 'bank_transfer_beneficiary' => $beneficiary,
            'transaction_count' => 1, 'document_kind' => 'receipt',
            'routing_document_kind' => $isBeneficiary ? 'customer_receipt' : 'supplier_payment',
            'document_direction' => $isBeneficiary ? 'outgoing' : ($isPayer ? 'incoming' : 'unknown'),
            'company_identity_mismatch' => $counterparty === null,
            'document_perspective_conflict' => false,
            'counterparty' => $counterparty, 'buyer_name' => null,
            'issuer_name' => preg_match('/Copyright[^\r\n]*?\d{4}\s+([\p{L} ]+?)\s+\d/u', $text, $issuer) ? trim($issuer[1]) : null,
            'transaction_date' => $date->format('Y-m-d'), 'payment_date' => $date->format('Y-m-d'),
            'reference_number' => $reference, 'description' => $isBeneficiary ? "Bank transfer received from $payer for {$match['reference']}" : "Bank transfer paid to $beneficiary for {$match['reference']}",
            'currency' => 'MYR', 'subtotal' => $amount, 'tax' => 0, 'total' => $amount,
            'payment_status' => 'paid', 'payment_evidence_kind' => 'settlement_confirmation',
            'payment_status_evidence' => 'Transaction Approval; Approval Status Success',
            'economic_event' => 'Completed bank transfer', 'trade_nature' => 'trade',
            'treatment_summary' => 'Settle the named party ledger through bank.',
            'business_context_reason' => 'The transfer identifies payer and beneficiary; no expense or service period is stated.',
            'service_periods' => [], 'item_assessments' => [],
            'overall_confidence' => $counterparty === null ? 0.6 : 0.95,
            'lines' => $counterparty === null ? [] : ($isBeneficiary ? [$bankLine, $partyLine] : [$partyLine, $bankLine]),
        ]);

        return $payload;
    }
}
