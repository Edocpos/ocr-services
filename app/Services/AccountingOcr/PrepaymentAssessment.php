<?php

namespace App\Services\AccountingOcr;

use DateTimeImmutable;

class PrepaymentAssessment
{
    /** Only assess and flag: no invented dates, allocations, schedules or forced journal changes. */
    public function assess(array $payload, array $lines, array $context, array &$warnings): array
    {
        $postingDate = $this->date($context['posting_date'] ?? null) ?? $this->date($payload['transaction_date'] ?? null);
        $status = $payload['payment_status'] ?? 'unknown';
        $paymentStatus = in_array($status, ['paid', 'unpaid', 'partially_paid', 'unknown'], true) ? $status : 'unknown';
        $paymentEvidence = is_string($payload['payment_status_evidence'] ?? null) ? trim($payload['payment_status_evidence']) : '';
        if ($paymentStatus !== 'unknown' && $paymentEvidence === '') {
            $paymentStatus = 'unknown';
            $warnings[] = $this->issue('payment_status_evidence_missing', 'Payment status has no supporting document evidence.');
        }
        $rawPeriods = array_key_exists('service_periods', $payload) ? $payload['service_periods'] : [];
        $requiresReview = false;
        if (! is_array($rawPeriods) || ! array_is_list($rawPeriods)) {
            $rawPeriods = [];
            $requiresReview = true;
            $warnings[] = $this->issue('invalid_service_period', 'The provider returned invalid service period data.');
        }
        $periods = [];
        foreach ($rawPeriods as $raw) {
            if (! is_array($raw)) {
                $warnings[] = $this->issue('invalid_service_period', 'A service period was not a structured item.');
                $requiresReview = true;

                continue;
            }
            $start = $this->date($raw['start_date'] ?? null);
            $end = $this->date($raw['end_date'] ?? null);
            $kind = $raw['period_kind'] ?? 'unknown';
            $kind = in_array($kind, ['service_coverage', 'billing_period', 'payment_terms', 'unknown'], true) ? $kind : 'unknown';
            $evidence = is_string($raw['evidence'] ?? null) ? trim($raw['evidence']) : '';
            $invalid = ($start === null && ($raw['start_date'] ?? null) !== null)
                || ($end === null && ($raw['end_date'] ?? null) !== null)
                || ($start !== null && $end !== null && $end < $start);
            $amount = $raw['amount'] ?? null;
            if ($amount !== null && (! is_numeric($amount) || (float) $amount < 0)) {
                $amount = null;
                $invalid = true;
            }
            if ($invalid) {
                $warnings[] = $this->issue('invalid_service_period', 'Coverage dates or amount are invalid; review the document.');
            }
            $relation = match (true) {
                $invalid || $kind === 'payment_terms' || $postingDate === null || $start === null || $end === null => 'unknown',
                $start > $postingDate => 'future',
                $end < $postingDate => 'past',
                default => 'covers_posting_date',
            };
            // Billing periods may imply coverage but need human interpretation. Never treat due dates as coverage.
            $candidate = $kind !== 'payment_terms' && (
                ($raw['prepayment_candidate'] ?? false) === true
                || in_array($relation, ['future', 'covers_posting_date'], true)
                || $kind === 'unknown'
                || (in_array($kind, ['service_coverage', 'billing_period'], true) && $relation === 'unknown')
            );
            if ($candidate || $invalid || ($kind !== 'payment_terms' && $evidence === '')) {
                $requiresReview = true;
            }
            $periods[] = [
                'description' => is_string($raw['description'] ?? null) ? trim($raw['description']) : null,
                'amount' => $amount === null ? null : round((float) $amount, 2),
                'start_date' => $start,
                'end_date' => $end,
                'period_kind' => $kind,
                'evidence' => $evidence,
                'relation_to_posting_date' => $relation,
                'prepayment_candidate' => $candidate,
            ];
        }
        $registry = new AccountCodeRegistry;
        $hasPrepayment = false;
        $hasAdvance = false;
        foreach ($lines as $line) {
            $prefix = $line['recommendation']['parent_code'] ?? $registry->prefixForAccount((string) ($line['account_code'] ?? ''));
            $subtype = $prefix === null ? ($line['account_subtype'] ?? null) : ($registry->guidance($prefix)['subtype'] ?? null);
            $hasPrepayment = $hasPrepayment || $subtype === 'prepayment';
            $hasAdvance = $hasAdvance || in_array($subtype, ['supplier_advance', 'customer_advance', 'refundable_deposit', 'accrual'], true);
        }
        if ($hasPrepayment || $hasAdvance) {
            $requiresReview = true;
        }
        if ($hasPrepayment && $periods === []) {
            $warnings[] = $this->issue('prepayment_coverage_missing', 'A prepaid account was proposed without coverage details; review the supporting contract.');
        }
        if ($hasPrepayment && $paymentStatus !== 'paid') {
            $warnings[] = $this->issue('prepayment_payment_uncertain', 'A prepaid account was proposed without evidence of full payment; confirm recognition with the accountant.');
        }
        if ($requiresReview) {
            $warnings[] = $this->issue('period_treatment_requires_review', 'Confirm expense timing, prepayment, deposit or advance treatment before posting. No allocation schedule was generated.');
        }

        return [
            'status' => $requiresReview ? 'review_required' : 'no_prepayment_indicated',
            'posting_date' => $postingDate,
            'posting_date_source' => $this->date($context['posting_date'] ?? null) !== null ? 'request' : ($postingDate === null ? null : 'transaction_date'),
            'payment_status' => $paymentStatus,
            'payment_status_evidence' => $paymentEvidence === '' ? null : $paymentEvidence,
            'service_periods' => $periods,
            'has_prepayment_account' => $hasPrepayment,
            'requires_manual_review' => $requiresReview,
            'schedule_generated' => false,
        ];
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function issue(string $code, string $message): array
    {
        return ['field' => 'prepayment_assessment', 'code' => $code, 'message' => $message];
    }
}
