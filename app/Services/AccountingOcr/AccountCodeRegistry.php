<?php

namespace App\Services\AccountingOcr;

class AccountCodeRegistry
{
    /** Compatibility for existing submitted accounts; required classifications use the PDF hierarchy. */
    private const ALIASES = [
        'PL/OI/RIN/SLIC' => 'PL/TI/TIN/SLIC',
        'PL/OI/RIN/SVIC' => 'PL/TI/TIN/SVIC',
        'PL/OI/TIN/SLIC' => 'PL/TI/TIN/SLIC',
        'PL/OI/TIN/SVIC' => 'PL/TI/TIN/SVIC',
        'PL/OE/OEX/CSSL' => 'PL/TE/TEX/CSSL',
        'PL/OE/OEX/DTEX' => 'PL/TE/TEX/DTEX',
        'PL/OE/OEX/MKEX' => 'PL/TE/TEX/MKEX',
        'PL/OE/OEX/OPEX' => 'PL/TE/TEX/OPEX',
        'PL/OE/TEX/CSSL' => 'PL/TE/TEX/CSSL',
        'PL/OE/TEX/DTEX' => 'PL/TE/TEX/DTEX',
        'PL/OE/TEX/MKEX' => 'PL/TE/TEX/MKEX',
        'PL/OE/TEX/OPEX' => 'PL/TE/TEX/OPEX',
        'PL/NE/AEX/DPRC' => 'PL/OE/OEX/DPRC',
        'PL/NE/AEX/GNEX' => 'PL/OE/OEX/GNEX',
        'PL/NI/OIN/OTNC' => 'PL/OI/OIN/OTNC',
    ];

    private ?array $library = null;

    public function library(): array
    {
        return $this->library ??= json_decode(file_get_contents(dirname(__DIR__, 3).'/resources/accounting/accounts.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function normalize(?string $prefix): ?string
    {
        $prefix = strtoupper(rtrim(trim((string) $prefix), '/'));
        $prefix = self::ALIASES[$prefix] ?? $prefix;

        return isset($this->library()['accounts'][$prefix]) ? $prefix : null;
    }

    public function prefixForAccount(string $code): ?string
    {
        $parts = explode('/', strtoupper(trim($code)));

        return count($parts) === 5 ? $this->normalize(implode('/', array_slice($parts, 0, 4))) : null;
    }

    public function metadata(string $canonical): array
    {
        $entry = $this->library()['accounts'][$canonical];

        return array_intersect_key($entry, array_flip(['type', 'subtype', 'normal_balance']));
    }

    public function guidance(?string $prefix): ?array
    {
        $canonical = $this->normalize($prefix);
        if ($canonical === null) {
            return null;
        }

        return ['parent_code' => $canonical] + $this->library()['accounts'][$canonical];
    }

    /** Link each supplied account to the dictionary without repeating category text hundreds of times. */
    public function enrichAccounts(array $accounts): array
    {
        return array_map(fn (array $account): array => array_merge($account, [
            'category_prefix' => $this->accountPrefix($account),
            'semantic_role' => $this->accountRole($account),
        ]), $accounts);
    }

    public function roles(): array
    {
        return array_values(array_unique([...array_column($this->library()['accounts'], 'role'), 'input_tax']));
    }

    public function accountPrefix(array $account): ?string
    {
        return $this->prefixForAccount((string) $account['code']) ?? $this->normalize($account['acc01_prefix'] ?? null);
    }

    public function accountRole(array $account): ?string
    {
        $prefix = $this->accountPrefix($account);
        if ($prefix !== null) {
            return $this->guidance($prefix)['role'];
        }
        $role = $account['role'] ?? match ($account['subtype'] ?? '') {
            'accounts_payable' => 'trade_payable',
            'accounts_receivable' => 'trade_receivable',
            'other' => $account['type'] ?? null,
            default => $account['subtype'] ?? null,
        };

        return in_array($role, $this->roles(), true) ? $role : null;
    }
}
