<?php

namespace App\Services\AccountingOcr;

class PaymentEvidenceAssessment
{
    /** Terms and instructions cannot establish settlement or an outstanding balance. */
    public function normalize(array $payload): array
    {
        $kind = $payload['payment_evidence_kind'] ?? 'none';
        if (! in_array($kind, ['settlement_confirmation', 'outstanding_confirmation', 'payment_terms', 'payment_instructions', 'none'], true)) {
            $kind = 'none';
        }
        $evidence = is_string($payload['payment_status_evidence'] ?? null) ? trim($payload['payment_status_evidence']) : '';
        $status = $payload['payment_status'] ?? 'unknown';
        $supported = $evidence !== '' && (
            (in_array($status, ['paid', 'partially_paid'], true) && $kind === 'settlement_confirmation')
            || ($status === 'unpaid' && $kind === 'outstanding_confirmation')
        );
        $payload['payment_status'] = $supported ? $status : 'unknown';
        $payload['payment_evidence_kind'] = $kind;
        if ($kind !== 'settlement_confirmation' || ! $supported) {
            $payload['payment_instruction_reference'] ??= $payload['payment_reference'] ?? null;
            $payload['payment_method'] = null;
            $payload['payment_reference'] = null;
            $payload['payment_date'] = null;
        }

        return $payload;
    }
}
