<?php

namespace App\Services\AccountingOcr;

class AccountRecommendationService
{
    public function __construct(private readonly AccountCodeRegistry $registry) {}

    /**
     * @param  array<string, mixed>  $line
     * @param  list<array<string, mixed>>  $accounts
     * @return array<string, mixed>|null
     */
    public function recommend(array $line, array $accounts): ?array
    {
        $canonical = $this->registry->normalize($this->stringOrNull($line['suggested_prefix'] ?? null));
        if ($canonical === null) {
            return null;
        }

        $prefix = $this->registry->preferredPrefix($canonical, $accounts);
        $number = $this->nextNumber($prefix, $accounts);
        $metadata = $this->registry->metadata($canonical);

        return [
            'provisional_code' => $number === null ? null : $prefix.'/'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
            'suggested_name' => $this->stringOrNull($line['suggested_name'] ?? null) ?? 'Recommended Account',
            'account_type' => $metadata['type'],
            'account_subtype' => $metadata['subtype'],
            'normal_balance' => $metadata['normal_balance'],
            'requires_creation' => true,
            'reason' => trim((string) ($line['reason'] ?? 'No suitable submitted account was found.')),
            'evidence' => trim((string) ($line['evidence'] ?? '')),
            'confidence' => $this->confidence($line['confidence'] ?? null),
        ];
    }

    /** @param list<array<string, mixed>> $accounts */
    private function nextNumber(string $prefix, array $accounts): ?int
    {
        $highest = 9999;
        foreach ($accounts as $account) {
            $code = strtoupper(trim((string) ($account['code'] ?? '')));
            if (preg_match('/^'.preg_quote($prefix, '/').'\/(\d{5})$/', $code, $matches) !== 1) {
                continue;
            }

            $number = (int) $matches[1];
            if ($number >= 10000 && $number <= 89999) {
                $highest = max($highest, $number);
            }
        }

        return $highest >= 89999 ? null : $highest + 1;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function confidence(mixed $value): float
    {
        return round(max(0, min(1, is_numeric($value) ? (float) $value : 0)), 4);
    }
}
