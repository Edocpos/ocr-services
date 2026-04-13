<?php

namespace App\Services\IcOcr;

class IcValueNormalizer
{
    public function normalizeIcDigits(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value);
        if (! is_string($digits) || strlen($digits) !== 12) {
            return null;
        }

        return $digits;
    }

    public function formatIcDisplay(?string $digits): ?string
    {
        if ($digits === null || strlen($digits) !== 12) {
            return null;
        }

        return sprintf(
            '%s-%s-%s',
            substr($digits, 0, 6),
            substr($digits, 6, 2),
            substr($digits, 8, 4)
        );
    }

    public function normalizeName(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($value === '') {
            return null;
        }

        return mb_strtoupper($value);
    }

    public function normalizeAddress(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/\R+/u', ', ', $value);
        $value = preg_replace('/\s+/u', ' ', (string) $value);
        $value = trim((string) $value, " \t\n\r\0\x0B,");

        return $value !== '' ? $value : null;
    }
}
