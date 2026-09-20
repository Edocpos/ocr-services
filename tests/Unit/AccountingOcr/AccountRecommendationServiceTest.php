<?php

namespace Tests\Unit\AccountingOcr;

use App\Services\AccountingOcr\AccountCodeRegistry;
use App\Services\AccountingOcr\AccountRecommendationService;
use PHPUnit\Framework\TestCase;

class AccountRecommendationServiceTest extends TestCase
{
    public function test_it_returns_parent_metadata_without_a_leaf_code_or_duplicate_name(): void
    {
        $service = new AccountRecommendationService(new AccountCodeRegistry);

        $result = $service->recommend([
            'suggested_prefix' => 'BS/NA/PPE/MTVE',
            'suggested_name' => 'Delivery Van',
            'confidence' => 0.9,
        ], []);

        $this->assertSame('asset', $result['account_type']);
        $this->assertSame('other', $result['account_subtype']);
        $this->assertSame('BS/NA/PPE/MTVE', $result['parent_code']);
        $this->assertSame('MTVE', $result['parent_definition_key']);
        $this->assertTrue($result['create_parent_if_missing']);
        $this->assertArrayNotHasKey('provisional_code', $result);
        $this->assertArrayNotHasKey('suggested_name', $result);
    }

    public function test_it_normalizes_trade_payables_to_the_tptc_parent(): void
    {
        $service = new AccountRecommendationService(new AccountCodeRegistry);

        $result = $service->recommend([
            'suggested_prefix' => 'BS/CL/OPY/OPCR',
            'suggested_name' => 'Trade Payables',
        ], []);

        $this->assertSame('liability', $result['account_type']);
        $this->assertSame('trade_payable', $result['account_subtype']);
        $this->assertSame('BS/CL/TPY/TPTC', $result['parent_code']);
        $this->assertSame('TPTC', $result['parent_definition_key']);
    }

    public function test_it_rejects_an_unknown_hierarchy(): void
    {
        $service = new AccountRecommendationService(new AccountCodeRegistry);

        $this->assertNull($service->recommend([
            'suggested_prefix' => 'PL/XX/FAKE/NOPE',
        ], []));
    }
}
