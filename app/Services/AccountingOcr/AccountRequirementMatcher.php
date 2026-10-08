<?php

namespace App\Services\AccountingOcr;

class AccountRequirementMatcher
{
    public function __construct(private readonly AccountCodeRegistry $registry) {}

    /** Validate the required treatment first, then the selected account. Never create or substitute an account. */
    public function match(array $line, array $accounts): array
    {
        $role = $line['required_role'] ?? null;
        $rawPrefix = $line['required_prefix'] ?? null;
        $prefix = is_string($rawPrefix) ? $this->registry->normalize($rawPrefix) : null;
        $type = $line['required_account_type'] ?? null;
        $guidance = $this->registry->guidance($prefix);
        $control = in_array($role, ['trade_payable', 'trade_receivable'], true) || ($line['requires_control_account'] ?? false) === true;
        $controlRole = match ($role) {
            'trade_payable' => 'ap',
            'trade_receivable' => 'ar',
            default => $line['required_control_role'] ?? null,
        };
        $valid = in_array($role, $this->registry->roles(), true)
            && in_array($type, ['asset', 'liability', 'equity', 'revenue', 'expense'], true)
            && array_key_exists('requires_control_account', $line) && is_bool($line['requires_control_account'])
            && ($rawPrefix === null || $prefix !== null)
            && ($guidance === null || ($guidance['role'] === $role && $guidance['type'] === $type))
            && (($line['required_control_role'] ?? null) === null || ($line['required_control_role'] === $controlRole && $control))
            && (! $control || in_array($controlRole, ['ap', 'ar'], true))
            && (! $control || ($controlRole === 'ap' && $role === 'trade_payable' && $type === 'liability') || ($controlRole === 'ar' && $role === 'trade_receivable' && $type === 'asset'));
        // A role may not change its accounting type just because another type is available.
        $types = array_unique(array_column(array_filter($this->registry->library()['accounts'], fn ($entry) => $entry['role'] === $role), 'type'));
        $valid = $valid && ($role === 'input_tax' ? $type === 'asset' : in_array($type, $types, true));
        $requirement = [
            'role' => $role, 'account_type' => $type, 'parent_code' => $prefix,
            'account_subtype' => $guidance['subtype'] ?? null,
            'requires_control_account' => $control, 'control_role' => $control ? $controlRole : null,
        ];
        if (! $valid) {
            return ['account' => null, 'requirement' => $requirement, 'error_code' => 'ACCOUNT_REQUIREMENT_INVALID', 'available_codes' => []];
        }
        $eligible = array_values(array_filter($accounts, function (array $account) use ($type, $role, $prefix, $control, $controlRole): bool {
            $accountPrefix = $this->registry->accountPrefix($account);
            $accountGuidance = $this->registry->guidance($accountPrefix);

            return $account['type'] === $type
                && ($accountGuidance === null || $accountGuidance['type'] === $account['type'])
                && $this->registry->accountRole($account) === $role
                && ($prefix === null || $accountPrefix === $prefix)
                && (! $control || (($account['is_control_account'] ?? false) === true && ($account['control_role'] ?? null) === $controlRole));
        }));
        $selected = strtoupper(trim((string) ($line['selected_account_code'] ?? '')));
        if ($selected === '' && ($line['account_error'] ?? null) === 'ACCOUNT_NOT_AVAILABLE') {
            return ['account' => null, 'requirement' => $requirement, 'error_code' => $control && $eligible !== [] ? 'ACCOUNT_SELECTION_INVALID' : 'ACCOUNT_NOT_AVAILABLE', 'available_codes' => array_column($eligible, 'code')];
        }
        foreach ($eligible as $account) {
            if (strtoupper(trim($account['code'])) === $selected && ($line['account_error'] ?? null) === null) {
                return ['account' => $account, 'requirement' => $requirement, 'error_code' => null, 'available_codes' => array_column($eligible, 'code')];
            }
        }

        return [
            'account' => null, 'requirement' => $requirement,
            'error_code' => $eligible === [] ? 'ACCOUNT_NOT_AVAILABLE' : 'ACCOUNT_SELECTION_INVALID',
            'available_codes' => array_column($eligible, 'code'),
        ];
    }
}
