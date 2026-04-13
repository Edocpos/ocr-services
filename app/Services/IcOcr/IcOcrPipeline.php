<?php

namespace App\Services\IcOcr;

use App\Contracts\Ocr\OcrClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class IcOcrPipeline
{
    public function __construct(
        private readonly OcrClient $ocrClient,
        private readonly IcImagePreprocessor $imagePreprocessor,
        private readonly IcFieldExtractor $fieldExtractor,
        private readonly IcValueNormalizer $normalizer,
        private readonly IcRuleEngine $ruleEngine,
        private readonly PostcodeDistrictResolver $districtResolver,
        private readonly IcConfidenceEvaluator $confidenceEvaluator,
        private readonly OcrUsageEstimator $usageEstimator,
        private readonly IcResponseBuilder $responseBuilder,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function process(UploadedFile $file): array
    {
        $content = file_get_contents($file->getRealPath());
        if ($content === false) {
            return $this->responseBuilder->failure(
                'file_read_error',
                'Uploaded file could not be read.',
                $this->usageEstimator->estimate(0, null)
            );
        }

        $preparedImage = $this->imagePreprocessor->prepare($content);
        $content = $preparedImage['content'];
        $prepMeta = $preparedImage['meta'];

        $imageSha256 = hash('sha256', $content);

        Log::info('ocr_input_diagnostics', [
            'image_sha256' => $imageSha256,
            'image_preprocess' => $prepMeta,
        ]);

        $ocrPayload = $this->ocrClient->detectText($content);
        $usage = $this->usageEstimator->estimate(1, $ocrPayload['usage'] ?? null);
        $raw = $this->fieldExtractor->extract($ocrPayload);

        $icDigits = $this->normalizer->normalizeIcDigits($raw['ic_number']);
        $ruleResult = $this->ruleEngine->derive($icDigits);

        $normalized = [
            'ic_number' => $this->normalizer->formatIcDisplay($icDigits),
            'name' => $this->normalizer->normalizeName($raw['name']),
            'address' => $this->normalizer->normalizeAddress($raw['address']),
        ];

        $district = $this->districtResolver->resolve($normalized['address'], $ruleResult['state']);
        $ruleResult['district'] = $district['district'];
        $ruleResult['district_source'] = $district['source'];

        $confidence = $this->confidenceEvaluator->evaluate($ocrPayload, $raw, $normalized, $ruleResult);
        if ($confidence['block'] === true) {
            Log::info('ocr_pipeline_investigation', [
                'image_sha256' => $imageSha256,
                'ocr_text_sha256' => hash('sha256', (string) ($ocrPayload['full_text'] ?? '')),
                'ocr_line_count' => count($ocrPayload['lines'] ?? []),
                'ocr_overall_confidence' => $ocrPayload['overall_confidence'] ?? null,
                'result_status' => 'failed_low_confidence',
                'image_preprocess' => $prepMeta,
                'confidence' => [
                    'overall' => $confidence['overall'],
                    'ic_number' => $confidence['ic_number'],
                    'name' => $confidence['name'],
                    'address' => $confidence['address'],
                ],
            ]);

            return $this->responseBuilder->lowConfidence($confidence, $usage);
        }

        Log::info('ocr_pipeline_investigation', [
            'image_sha256' => $imageSha256,
            'ocr_text_sha256' => hash('sha256', (string) ($ocrPayload['full_text'] ?? '')),
            'ocr_line_count' => count($ocrPayload['lines'] ?? []),
            'ocr_overall_confidence' => $ocrPayload['overall_confidence'] ?? null,
            'result_status' => 'success',
            'image_preprocess' => $prepMeta,
            'derived' => [
                'state' => $ruleResult['state'],
                'district' => $ruleResult['district'],
            ],
            'confidence' => [
                'overall' => $confidence['overall'],
                'ic_number' => $confidence['ic_number'],
                'name' => $confidence['name'],
                'address' => $confidence['address'],
            ],
        ]);

        return $this->responseBuilder->success($raw, $normalized, $ruleResult, [], $confidence, $usage);
    }
}
