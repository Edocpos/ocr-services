<?php

namespace Tests\Unit\AccountingOcr;

use App\Services\AccountingOcr\AccountCodeRegistry;
use App\Services\AccountingOcr\AccountRequirementMatcher;
use PHPUnit\Framework\TestCase;

class AccountRequirementMatcherTest extends TestCase
{
    private function requirement(?string $selected = null): array
    {
        return [
            'selected_account_code' => $selected, 'required_role' => 'trade_payable',
            'required_account_type' => 'liability', 'required_prefix' => 'BS/CL/TPY/TPTC',
            'requires_control_account' => true, 'required_control_role' => 'ap',
            'account_error' => null,
        ];
    }

    private function account(bool $control = true): array
    {
        return ['code' => 'BS/CL/TPY/TPTC/10000', 'name' => 'AP Control', 'type' => 'liability', 'subtype' => 'accounts_payable', 'is_control_account' => $control, 'control_role' => $control ? 'ap' : null];
    }

    public function test_other_creditors_cannot_replace_trade_payables(): void
    {
        $other = ['code' => 'BS/CL/OPY/OPCR/10000', 'name' => 'Other Creditors', 'type' => 'liability', 'subtype' => 'other_payable'];
        $result = (new AccountRequirementMatcher(new AccountCodeRegistry))->match($this->requirement($other['code']), [$other]);
        $this->assertSame('ACCOUNT_NOT_AVAILABLE', $result['error_code']);
        $this->assertNull($result['account']);
        $this->assertSame('trade_payable', $result['requirement']['role']);
        $this->assertArrayNotHasKey('create_parent_if_missing', $result['requirement']);
    }

    public function test_supplier_ledger_or_missing_control_metadata_cannot_bypass_ap_control(): void
    {
        $matcher = new AccountRequirementMatcher(new AccountCodeRegistry);
        foreach ([$this->account(false), array_diff_key($this->account(), array_flip(['is_control_account', 'control_role']))] as $account) {
            $this->assertSame('ACCOUNT_NOT_AVAILABLE', $matcher->match($this->requirement($account['code']), [$account])['error_code']);
        }
    }

    public function test_valid_control_can_be_selected_but_is_not_substituted_automatically(): void
    {
        $matcher = new AccountRequirementMatcher(new AccountCodeRegistry);
        $account = $this->account();
        $this->assertSame($account, $matcher->match($this->requirement($account['code']), [$account])['account']);
        $result = $matcher->match($this->requirement('INVENTED'), [$account]);
        $this->assertSame('ACCOUNT_SELECTION_INVALID', $result['error_code']);
        $this->assertNull($result['account']);
    }

    public function test_ar_cannot_be_selected_as_ap_and_type_cannot_be_changed_to_fit(): void
    {
        $line = $this->requirement($this->account()['code']);
        $line['required_control_role'] = 'ar';
        $account = $this->account();
        $account['control_role'] = 'ar';
        $this->assertSame('ACCOUNT_REQUIREMENT_INVALID', (new AccountRequirementMatcher(new AccountCodeRegistry))->match($line, [$account])['error_code']);
        $line['required_account_type'] = 'expense';
        $this->assertSame('ACCOUNT_REQUIREMENT_INVALID', (new AccountRequirementMatcher(new AccountCodeRegistry))->match($line, [$account])['error_code']);
    }

    public function test_semantic_requirements_are_mandatory_even_for_existing_codes(): void
    {
        $result = (new AccountRequirementMatcher(new AccountCodeRegistry))->match(['selected_account_code' => $this->account()['code']], [$this->account()]);
        $this->assertSame('ACCOUNT_REQUIREMENT_INVALID', $result['error_code']);
    }

    public function test_pdf_source_names_and_roles_are_not_replaced_by_aliases(): void
    {
        $registry = new AccountCodeRegistry;
        $this->assertSame('TRADE CREDITORS', $registry->guidance('BS/CL/TPY/TPTC')['name']);
        $this->assertSame('TRADE DEPOSIT RECEIVABLES', $registry->guidance('BS/CL/TPY/TPTD')['name']);
        $this->assertSame('customer_advance', $registry->guidance('BS/CL/TPY/TPTD')['role']);
        $this->assertSame('ppe', $registry->guidance('BS/NA/PPE/COMP')['role']);
        $this->assertSame('inventory', $registry->guidance('BS/CA/IVT/TSTK')['role']);
        $this->assertContains('Accounts Payable', $registry->guidance('BS/CL/TPY/TPTC')['aliases']);
    }

    public function test_custom_codes_need_explicit_semantics_and_can_use_the_source_category(): void
    {
        $account = $this->account();
        $account['code'] = 'AP-CONTROL';
        $account['acc01_prefix'] = 'BS/CL/TPY/TPTC';
        $result = (new AccountRequirementMatcher(new AccountCodeRegistry))->match($this->requirement('AP-CONTROL'), [$account]);
        $this->assertSame('AP-CONTROL', $result['account']['code']);
    }
}
