<?php

namespace App\Services\AccountingOcr;

class AccountRecommendationService
{
    public function __construct(private readonly AccountCodeRegistry $registry) {}

    /** Describe a missing account for review using the validated requirement, never a generated leaf code. */
    public function recommend(array $line, array $requirement): array
    {
        $guidance = $this->registry->guidance($requirement['parent_code']);
        $name = is_string($line['suggested_account_name'] ?? null) ? trim($line['suggested_account_name']) : '';
        $parent = $requirement['parent_code'];

        return $requirement + [
            'suggested_name' => $name !== '' ? mb_substr($name, 0, 255) : ($guidance['name'] ?? ucwords(str_replace('_', ' ', $requirement['role']))),
            'parent_name' => $guidance['name'] ?? null,
            'parent_definition_key' => $parent === null ? null : basename($parent),
            'purpose' => $guidance['purpose'] ?? null,
            'usage_examples' => $guidance['usage_examples'] ?? [],
            'reason' => is_string($line['reason'] ?? null) ? trim($line['reason']) : null,
            'requires_manual_review' => true,
        ];
    }
}
