<?php

namespace App\Services\StatutoryOcr;

class ReceiptResponseBuilder
{
    /**
     * @param  array<string, mixed>  $extracted
     * @param  array<string, float>  $fieldConfidence
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    public function success(
        string $scheme,
        array $extracted,
        array $fieldConfidence,
        float $overall,
        bool $requiresManualReview,
        array $warnings,
        string $requestId,
        string $source,
    ): array {
        return [
            'data' => [
                'extracted' => $extracted,
            ],
            'meta' => [
                'scheme' => $scheme,
                'confidence' => $overall,
                'field_confidence' => $fieldConfidence,
                'requires_manual_review' => $requiresManualReview,
                'warnings' => array_values($warnings),
                'request_id' => $requestId,
                'source' => $source,
            ],
        ];
    }
}
