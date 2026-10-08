<?php

namespace Tests\Unit\AccountingOcr;

use App\Services\AccountingOcr\AccountCodeRegistry;
use App\Services\AccountingOcr\PrepaymentAssessment;
use PHPUnit\Framework\TestCase;

class PrepaymentAssessmentTest extends TestCase
{
    private function assess(array $period, array $extra = [], array $lines = []): array
    {
        $warnings = [];
        $result = (new PrepaymentAssessment)->assess([
            'transaction_date' => '2026-10-07',
            'service_periods' => [$period + [
                'period_kind' => 'service_coverage', 'evidence' => 'Printed coverage', 'prepayment_candidate' => false,
            ]],
        ] + $extra, $lines, [], $warnings);

        return [$result, array_column($warnings, 'code')];
    }

    public function test_future_coverage_requires_review_even_when_provider_misses_the_candidate(): void
    {
        [$result] = $this->assess(['start_date' => '2027-01-01', 'end_date' => '2027-12-31']);
        $this->assertTrue($result['requires_manual_review']);
        $this->assertSame('future', $result['service_periods'][0]['relation_to_posting_date']);
        $this->assertFalse($result['schedule_generated']);
    }

    public function test_past_consumed_coverage_is_not_automatically_prepaid(): void
    {
        [$result] = $this->assess(['start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
        $this->assertFalse($result['requires_manual_review']);
        $this->assertSame('past', $result['service_periods'][0]['relation_to_posting_date']);
    }

    public function test_due_dates_do_not_trigger_prepaid_treatment(): void
    {
        [$result] = $this->assess(['period_kind' => 'payment_terms', 'start_date' => '2027-01-01', 'end_date' => '2027-01-30', 'prepayment_candidate' => true]);
        $this->assertFalse($result['requires_manual_review']);
        $this->assertFalse($result['service_periods'][0]['prepayment_candidate']);
    }

    public function test_invalid_dates_and_reversed_ranges_require_review(): void
    {
        foreach ([['2026-02-30', '2027-01-01'], ['2027-12-31', '2027-01-01']] as [$start, $end]) {
            [$result, $warnings] = $this->assess(['start_date' => $start, 'end_date' => $end]);
            $this->assertTrue($result['requires_manual_review']);
            $this->assertContains('invalid_service_period', $warnings);
        }
    }

    public function test_missing_dates_and_unsubstantiated_payment_are_not_guessed(): void
    {
        [$result, $warnings] = $this->assess(['start_date' => null, 'end_date' => null], ['payment_status' => 'paid']);
        $this->assertTrue($result['requires_manual_review']);
        $this->assertSame('unknown', $result['payment_status']);
        $this->assertContains('payment_status_evidence_missing', $warnings);
    }

    public function test_customer_advances_and_other_creditors_keep_their_own_category(): void
    {
        $registry = new AccountCodeRegistry;
        foreach (['BS/CL/TPY/TPTD', 'BS/CL/OPY/OPCR', 'BS/CA/TRV/TRDP'] as $prefix) {
            $this->assertSame($prefix, $registry->guidance($prefix)['parent_code']);
        }
        $this->assertSame('income_tax_asset', $registry->metadata('BS/CA/CTX/CYTX')['subtype']);
    }

    public function test_user_data_cannot_override_system_category_links(): void
    {
        $result = (new AccountCodeRegistry)->enrichAccounts([[
            'code' => 'BS/CA/ORV/PRMT/10000', 'category_prefix' => 'PL/TI/TIN/SLIC',
        ]]);
        $this->assertSame('BS/CA/ORV/PRMT', $result[0]['category_prefix']);
    }

    public function test_malformed_period_data_cannot_silently_pass_review(): void
    {
        $warnings = [];
        $result = (new PrepaymentAssessment)->assess(['service_periods' => null], [], [], $warnings);
        $this->assertTrue($result['requires_manual_review']);
        $this->assertContains('invalid_service_period', array_column($warnings, 'code'));
    }
}
