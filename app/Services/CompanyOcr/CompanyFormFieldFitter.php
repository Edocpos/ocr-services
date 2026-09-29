<?php

namespace App\Services\CompanyOcr;

/**
 * Fits company OCR values to the shorter character limits used by the
 * portal subscription form and the Arkcloudant company form.
 *
 * Address overflow continues onto the next line instead of being dropped.
 */
class CompanyFormFieldFitter
{
    public const ADDRESS_LIMITS = [15, 30, 30];

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function fit(array $fields): array
    {
        $address = $this->fitAddressLines(
            $this->stringOrNull($fields['address_line_1'] ?? null),
            $this->stringOrNull($fields['address_line_2'] ?? null),
            $this->stringOrNull($fields['address_line_3'] ?? null),
        );

        $fields['company_name'] = $this->clip($this->stringOrNull($fields['company_name'] ?? null), 255);
        $fields['ssm_number'] = $this->clip($this->stringOrNull($fields['ssm_number'] ?? null), 12);
        $fields['local_trading_license'] = $this->clip($this->stringOrNull($fields['local_trading_license'] ?? null), 20);
        $fields['local_trading_license_issuer'] = $this->clipPlaceName(
            $this->stringOrNull($fields['local_trading_license_issuer'] ?? null),
            10,
        );
        $fields['tin_number'] = $this->clip($this->stringOrNull($fields['tin_number'] ?? null), 14);
        $fields['sst_number'] = $this->clip($this->stringOrNull($fields['sst_number'] ?? null), 20);
        $fields['phone'] = $this->clipDigits($this->stringOrNull($fields['phone'] ?? null), 10);
        $fields['email'] = $this->clip($this->stringOrNull($fields['email'] ?? null), 254);
        $fields['address_line_1'] = $address[0];
        $fields['address_line_2'] = $address[1];
        $fields['address_line_3'] = $address[2];
        $fields['postcode'] = $this->clipDigits($this->stringOrNull($fields['postcode'] ?? null), 5);
        $fields['city'] = $this->clipPlaceName($this->stringOrNull($fields['city'] ?? null), 50);
        $fields['state'] = $this->clipPlaceName($this->stringOrNull($fields['state'] ?? null), 50);
        $fields['country'] = $this->clipPlaceName($this->stringOrNull($fields['country'] ?? null), 50);
        $fields['lhdn_employer_no'] = $this->clip($this->stringOrNull($fields['lhdn_employer_no'] ?? null), 11);
        $fields['epf_employer_no'] = $this->clip($this->stringOrNull($fields['epf_employer_no'] ?? null), 20);
        $fields['socso_employer_no'] = $this->clip($this->stringOrNull($fields['socso_employer_no'] ?? null), 12);
        $fields['hrdc_employer_no'] = $this->clip($this->stringOrNull($fields['hrdc_employer_no'] ?? null), 20);
        $fields['zakat_employer_no'] = $this->clip($this->stringOrNull($fields['zakat_employer_no'] ?? null), 30);
        $fields['jtk_employer_no'] = $this->clip($this->stringOrNull($fields['jtk_employer_no'] ?? null), 30);

        return $fields;
    }

    /**
     * @return array{0:?string,1:?string,2:?string}
     */
    public function fitAddressLines(?string $line1, ?string $line2, ?string $line3): array
    {
        $parts = array_values(array_filter(
            [$line1, $line2, $line3],
            static fn (?string $line): bool => $line !== null && trim($line) !== '',
        ));

        $text = trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)) ?? '');
        if ($text === '') {
            return [null, null, null];
        }

        $lines = [];
        $remaining = $text;

        foreach (self::ADDRESS_LIMITS as $index => $limit) {
            $isLast = $index === count(self::ADDRESS_LIMITS) - 1;
            [$chunk, $remaining] = $this->takeChunk($remaining, $limit, $isLast);
            $lines[] = $chunk !== '' ? $chunk : null;
        }

        return [$lines[0], $lines[1], $lines[2]];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function takeChunk(string $text, int $limit, bool $hardStop): array
    {
        if ($text === '') {
            return ['', ''];
        }

        if (mb_strlen($text) <= $limit) {
            return [$text, ''];
        }

        $window = mb_substr($text, 0, $limit);
        $breakAt = mb_strrpos($window, ' ');

        if ($breakAt !== false && $breakAt > 0) {
            $chunk = rtrim(mb_substr($text, 0, $breakAt));
            $rest = ltrim(mb_substr($text, $breakAt));

            if ($hardStop) {
                return [rtrim(mb_substr($text, 0, $limit)), ''];
            }

            return [$chunk, $rest];
        }

        return [rtrim($window), $hardStop ? '' : ltrim(mb_substr($text, $limit))];
    }

    public function clip(?string $value, int $limit, bool $onWord = false): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        $window = mb_substr($value, 0, $limit);
        if ($onWord) {
            $breakAt = mb_strrpos($window, ' ');
            if ($breakAt !== false && $breakAt > 0) {
                $window = mb_substr($value, 0, $breakAt);
            }
        }

        $clipped = rtrim($window);

        return $clipped !== '' ? $clipped : null;
    }

    public function clipDigits(?string $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return null;
        }

        return substr($digits, 0, $limit);
    }

    public function clipPlaceName(?string $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        $cleaned = preg_replace('/\d+/u', '', $value) ?? '';
        $cleaned = preg_replace("/[^A-Za-z\\s.'()-]/u", '', $cleaned) ?? '';
        $cleaned = trim(preg_replace('/\s+/u', ' ', $cleaned) ?? '');

        return $this->clip($cleaned !== '' ? $cleaned : null, $limit, true);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
