<?php

namespace App\Services\StatutoryOcr;

class ReceiptValueNormalizer
{
    public function normalizeAmount(mixed $amount): ?float
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        if (is_int($amount) || is_float($amount)) {
            return round((float) $amount, 2);
        }

        $value = strtoupper(trim((string) $amount));
        $value = str_replace(["\u{00A0}", 'RM', 'MYR'], '', $value);
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $hasComma = str_contains($value, ',');
        $hasDot = str_contains($value, '.');

        if ($hasComma && $hasDot) {
            if (strrpos($value, ',') > strrpos($value, '.')) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif ($hasComma && preg_match('/,\d{1,2}$/', $value) === 1) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }

        $value = preg_replace('/[^0-9.\-]/', '', $value) ?? '';

        if ($value === '' || $value === '-' || $value === '.' || ! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    public function normalizeDate(mixed $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        $value = trim((string) $date);

        if (preg_match('/\b(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})\b/', $value, $matches) === 1) {
            $day = (int) $matches[1];
            $month = (int) $matches[2];
            $year = (int) $matches[3];

            if (checkdate($month, $day, $year)) {
                return sprintf('%02d/%02d/%04d', $day, $month, $year);
            }
        }

        if (preg_match('/\b(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})\b/', $value, $matches) === 1) {
            $year = (int) $matches[1];
            $month = (int) $matches[2];
            $day = (int) $matches[3];

            if (checkdate($month, $day, $year)) {
                return sprintf('%02d/%02d/%04d', $day, $month, $year);
            }
        }

        return null;
    }

    public function normalizePeriod(mixed $period): ?string
    {
        if ($period === null || $period === '') {
            return null;
        }

        $value = trim((string) $period);

        if (preg_match('/\b(0?[1-9]|1[0-2])[\/\-](20\d{2})\b/', $value, $matches) === 1) {
            return sprintf('%02d/%04d', (int) $matches[1], (int) $matches[2]);
        }

        if (preg_match('/\b(20\d{2})[\/\-](0?[1-9]|1[0-2])\b/', $value, $matches) === 1) {
            return sprintf('%02d/%04d', (int) $matches[2], (int) $matches[1]);
        }

        return null;
    }

    public function normalizeIdentifier(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtoupper(trim(preg_replace('/\s+/u', '', (string) $value) ?? ''));

        return $normalized !== '' ? $normalized : null;
    }

    public function normalizeText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $normalized !== '' ? $normalized : null;
    }
}
