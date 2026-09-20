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

        $metadata = $this->registry->metadata($canonical);
        if ($metadata['subtype'] === 'trade_payable') {
            $canonical = 'BS/CL/TPY/TPTC';
            $metadata = $this->registry->metadata($canonical);
        } elseif ($metadata['subtype'] === 'trade_receivable') {
            $canonical = 'BS/CA/TRV/TRDB';
            $metadata = $this->registry->metadata($canonical);
        }

        $prefix = $this->registry->preferredPrefix($canonical, $accounts);
        $parts = explode('/', $prefix);

        return [
            'account_type' => $metadata['type'],
            'account_subtype' => $metadata['subtype'],
            'parent_code' => $prefix,
            'parent_definition_key' => $parts[3],
            'create_parent_if_missing' => true,
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
