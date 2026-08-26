<?php

namespace App\Services\StatutoryOcr\Parsers;

use App\Services\StatutoryOcr\Contracts\SchemeParser;

abstract class AbstractSchemeParser implements SchemeParser
{
    /**
     * @param  array<string, mixed>  $fields
     * @param  list<string>  $warnings
     * @return array{
     *     extracted: array<string, mixed>,
     *     warnings: list<string>,
     *     requires_manual_review: bool,
     *     field_confidence: array<string, float>
     * }
     */
    protected function result(array $fields, array $warnings, bool $requiresManualReview, string $source): array
    {
        $extracted = [
            'receipt_number' => $fields['receipt_number'] ?? null,
            'payment_date' => $fields['payment_date'] ?? null,
            'contribution_period' => $fields['contribution_period'] ?? null,
            'contribution_reference' => $fields['contribution_reference'] ?? null,
            'employer_number' => $fields['employer_number'] ?? null,
            'employer_name' => $fields['employer_name'] ?? null,
            'transaction_id' => $fields['transaction_id'] ?? null,
            'bank' => $fields['bank'] ?? null,
            'amount' => $fields['amount'] ?? null,
            'payment_description' => $fields['payment_description'] ?? null,
        ];

        $baseConfidence = $source === 'embedded_text' ? 0.99 : 0.86;
        $fieldConfidence = [];

        foreach ($extracted as $field => $value) {
            if ($value !== null && $value !== '') {
                $fieldConfidence[$field] = $baseConfidence;
            }
        }

        return [
            'extracted' => $extracted,
            'warnings' => array_values(array_unique($warnings)),
            'requires_manual_review' => $requiresManualReview,
            'field_confidence' => $fieldConfidence,
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return list<string>
     */
    protected function missingCoreWarnings(array $fields, array $required): array
    {
        $warnings = [];

        foreach ($required as $field) {
            if (($fields[$field] ?? null) === null || $fields[$field] === '') {
                $warnings[] = 'The '.$field.' could not be extracted.';
            }
        }

        return $warnings;
    }
}
