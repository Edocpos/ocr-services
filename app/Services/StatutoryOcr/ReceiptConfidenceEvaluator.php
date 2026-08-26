<?php

namespace App\Services\StatutoryOcr;

class ReceiptConfidenceEvaluator
{
    /**
     * @param  array<string, mixed>  $extracted
     * @param  array<string, float>  $fieldConfidence
     * @return array{overall:float, field_confidence:array<string, float>, requires_manual_review:bool, unreadable:bool}
     */
    public function evaluate(
        array $extracted,
        array $fieldConfidence,
        bool $parserRequiresReview,
        ?float $ocrOverall,
        string $source,
    ): array {
        $present = [];
        foreach ($extracted as $value) {
            if ($value !== null && $value !== '') {
                $present[] = $value;
            }
        }

        $averageField = $fieldConfidence !== []
            ? array_sum($fieldConfidence) / count($fieldConfidence)
            : 0.0;

        $overall = $ocrOverall ?? ($source === 'embedded_text' ? 0.98 : 0.72);
        if ($fieldConfidence !== []) {
            $overall = round(($overall * 0.35) + ($averageField * 0.65), 4);
        }

        $minReview = (float) config('ocr.statutory_manual_review_min_confidence', 0.75);
        $minUnreadable = (float) config('ocr.statutory_unreadable_min_confidence', 0.40);
        $presentCount = count($present);

        $unreadable = $presentCount === 0 || ($presentCount < 2 && $overall < $minUnreadable);
        $requiresManualReview = $parserRequiresReview
            || $overall < $minReview
            || $presentCount < 3;

        return [
            'overall' => min(1.0, max(0.0, $overall)),
            'field_confidence' => $fieldConfidence,
            'requires_manual_review' => $unreadable ? true : $requiresManualReview,
            'unreadable' => $unreadable,
        ];
    }
}
