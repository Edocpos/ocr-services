<?php

namespace App\Services\CompanyOcr;

class CompanyValueNormalizer
{
    /**
     * @var array<string,string>
     */
    public const COMPANY_TYPES = [
        'sole_proprietor' => 'sole_proprietor',
        'partnership' => 'partnership',
        'llp' => 'llp',
        'sdn_bhd' => 'sdn_bhd',
        'bhd' => 'bhd',
    ];

    public function normalizeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value !== '' ? $value : null;
    }

    public function normalizeCompanyName(?string $value): ?string
    {
        return $this->normalizeText($value);
    }

    public function normalizeCompanyType(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtolower(trim(preg_replace('/[^a-z]+/i', '_', $value) ?? ''));
        $normalized = trim($normalized, '_');

        $aliases = [
            'sendirian_berhad' => 'sdn_bhd',
            'sdn_bhd' => 'sdn_bhd',
            'sdn' => 'sdn_bhd',
            'private_limited' => 'sdn_bhd',
            'berhad' => 'bhd',
            'bhd' => 'bhd',
            'public_limited' => 'bhd',
            'llp' => 'llp',
            'plt' => 'llp',
            'limited_liability_partnership' => 'llp',
            'partnership' => 'partnership',
            'perkongsian' => 'partnership',
            'sole_proprietor' => 'sole_proprietor',
            'sole_proprietorship' => 'sole_proprietor',
            'enterprise' => 'sole_proprietor',
            'perniagaan' => 'sole_proprietor',
            'perusahaan_persendirian' => 'sole_proprietor',
        ];

        $mapped = $aliases[$normalized] ?? $normalized;

        return self::COMPANY_TYPES[$mapped] ?? null;
    }

    public function normalizeSsmNumber(?string $value): ?string
    {
        $value = $this->normalizeText($value);
        if ($value === null) {
            return null;
        }

        $compact = strtoupper(preg_replace('/[\s.]+/', '', $value) ?? '');

        if (preg_match('/^(\d{12})$/', $compact, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^(\d{5,7})-?([A-Z])$/', $compact, $matches) === 1) {
            return $matches[1].'-'.$matches[2];
        }

        $digits = preg_replace('/\D+/', '', $compact) ?? '';
        if (strlen($digits) === 12) {
            return $digits;
        }

        return $compact !== '' ? $compact : null;
    }

    public function normalizeTinNumber(?string $value): ?string
    {
        $value = $this->normalizeText($value);
        if ($value === null) {
            return null;
        }

        $compact = strtoupper(preg_replace('/[\s.-]+/', '', $value) ?? '');

        return $compact !== '' ? $compact : null;
    }

    public function normalizeSstNumber(?string $value): ?string
    {
        $value = $this->normalizeText($value);
        if ($value === null) {
            return null;
        }

        $compact = strtoupper(preg_replace('/\s+/', '', $value) ?? '');

        if (preg_match('/^(W\d{2})-?(\d{4})-?(\d{8})$/', $compact, $matches) === 1) {
            return $matches[1].'-'.$matches[2].'-'.$matches[3];
        }

        return $compact !== '' ? $compact : null;
    }

    /**
     * @param  array<int,mixed>|null  $codes
     * @return array<int,string>
     */
    public function normalizeMsicCodes(?array $codes): array
    {
        if ($codes === null) {
            return [];
        }

        $normalized = [];

        foreach ($codes as $code) {
            if (! is_string($code) && ! is_numeric($code)) {
                continue;
            }

            $digits = preg_replace('/\D+/', '', (string) $code) ?? '';
            if (strlen($digits) !== 5) {
                continue;
            }

            $normalized[] = $digits;
        }

        return array_values(array_unique(array_slice($normalized, 0, 3)));
    }

    public function normalizeEmail(?string $value): ?string
    {
        $value = $this->normalizeText($value);
        if ($value === null) {
            return null;
        }

        $email = strtolower($value);

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * @return array{phone:?string,country_code:?string}
     */
    public function normalizePhone(?string $phone, ?string $countryCode = null): array
    {
        $phone = $this->normalizeText($phone);
        $countryCode = $this->normalizeText($countryCode);

        if ($phone === null) {
            return [
                'phone' => null,
                'country_code' => $this->normalizeCountryCode($countryCode),
            ];
        }

        $compact = preg_replace('/[^\d+]/', '', $phone) ?? '';

        if (str_starts_with($compact, '+60')) {
            return [
                'phone' => ltrim(substr($compact, 3), '0') ?: null,
                'country_code' => '+60',
            ];
        }

        if (str_starts_with($compact, '60') && strlen($compact) >= 10) {
            return [
                'phone' => ltrim(substr($compact, 2), '0') ?: null,
                'country_code' => '+60',
            ];
        }

        if (str_starts_with($compact, '0')) {
            return [
                'phone' => ltrim($compact, '0') ?: null,
                'country_code' => $this->normalizeCountryCode($countryCode) ?? '+60',
            ];
        }

        return [
            'phone' => ltrim($compact, '0') ?: null,
            'country_code' => $this->normalizeCountryCode($countryCode) ?? '+60',
        ];
    }

    public function normalizeCountryCode(?string $value): ?string
    {
        $value = $this->normalizeText($value);
        if ($value === null) {
            return null;
        }

        $compact = preg_replace('/[^\d+]/', '', $value) ?? '';
        if ($compact === '') {
            return null;
        }

        if (! str_starts_with($compact, '+')) {
            $compact = '+'.$compact;
        }

        return $compact;
    }

    public function normalizePostcode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return strlen($digits) === 5 ? $digits : null;
    }

    public function inferCompanyTypeFromName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $upper = mb_strtoupper($name);

        return match (true) {
            (bool) preg_match('/\bSDN\.?\s*BHD\.?\b/', $upper),
            (bool) preg_match('/\bSENDIRIAN\s+BERHAD\b/', $upper) => 'sdn_bhd',
            (bool) preg_match('/\bLLP\b/', $upper),
            (bool) preg_match('/\bPLT\b/', $upper) => 'llp',
            (bool) preg_match('/\bBHD\.?\b/', $upper),
            (bool) preg_match('/\bBERHAD\b/', $upper) => 'bhd',
            (bool) preg_match('/\bENTERPRISE\b/', $upper),
            (bool) preg_match('/\bTRADING\b/', $upper) => 'sole_proprietor',
            default => null,
        };
    }
}
