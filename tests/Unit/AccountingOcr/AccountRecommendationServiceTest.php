<?php

namespace Tests\Unit\AccountingOcr;

use App\Services\AccountingOcr\AccountCodeRegistry;
use App\Services\AccountingOcr\AccountRecommendationService;
use PHPUnit\Framework\TestCase;

class AccountRecommendationServiceTest extends TestCase
{
    public function test_it_starts_at_10000_and_preserves_five_digits(): void
    {
        $service = new AccountRecommendationService(new AccountCodeRegistry);

        $result = $service->recommend([
            'suggested_prefix' => 'BS/NA/PPE/MTVE',
            'suggested_name' => 'Delivery Van',
            'confidence' => 0.9,
        ], []);

        $this->assertSame('BS/NA/PPE/MTVE/10000', $result['provisional_code']);
        $this->assertSame('asset', $result['account_type']);
        $this->assertSame('debit', $result['normal_balance']);
    }

    public function test_it_does_not_allocate_outside_the_user_defined_range(): void
    {
        $service = new AccountRecommendationService(new AccountCodeRegistry);

        $result = $service->recommend([
            'suggested_prefix' => 'BS/CA/CNB/BANK',
            'suggested_name' => 'New Bank',
        ], [[
            'code' => 'BS/CA/CNB/BANK/89999',
            'name' => 'Last Bank',
        ]]);

        $this->assertNull($result['provisional_code']);
        $this->assertSame('bank', $result['account_subtype']);
    }

    public function test_it_rejects_an_unknown_hierarchy(): void
    {
        $service = new AccountRecommendationService(new AccountCodeRegistry);

        $this->assertNull($service->recommend([
            'suggested_prefix' => 'PL/XX/FAKE/NOPE',
        ], []));
    }
}
