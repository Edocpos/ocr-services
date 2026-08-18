<?php

namespace App\Services\CompanyOcr;

use App\Contracts\Ocr\OcrClient;
use App\Services\IcOcr\IcImagePreprocessor;
use App\Services\IcOcr\OcrUsageEstimator;
use App\Services\Ocr\GeminiCompanyOcrClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class CompanyOcrPipeline
{
    public function __construct(
        private readonly OcrClient $ocrClient,
        private readonly GeminiCompanyOcrClient $geminiCompanyClient,
        private readonly IcImagePreprocessor $imagePreprocessor,
        private readonly CompanyFieldExtractor $fieldExtractor,
        private readonly CompanyValueNormalizer $normalizer,
        private readonly CompanyRuleEngine $ruleEngine,
        private readonly CompanyConfidenceEvaluator $confidenceEvaluator,
        private readonly OcrUsageEstimator $usageEstimator,
        private readonly CompanyResponseBuilder $responseBuilder,
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

        Log::info('company_ocr_input_diagnostics', [
            'image_sha256' => $imageSha256,
            'image_preprocess' => $prepMeta,
        ]);

        $ocrPayload = $this->detectText($content);
        $usage = $this->usageEstimator->estimate(1, $ocrPayload['usage'] ?? null);
        $raw = $this->fieldExtractor->extract($ocrPayload);
        $normalized = $this->normalize($raw);
        $ruleResult = $this->ruleEngine->derive($raw, $normalized);

        $confidence = $this->confidenceEvaluator->evaluate($ocrPayload, $normalized);
        if ($confidence['block'] === true) {
            Log::info('company_ocr_pipeline_investigation', [
                'image_sha256' => $imageSha256,
                'result_status' => 'failed_low_confidence',
                'image_preprocess' => $prepMeta,
                'confidence' => $confidence,
            ]);

            return $this->responseBuilder->lowConfidence($confidence, $usage);
        }

        Log::info('company_ocr_pipeline_investigation', [
            'image_sha256' => $imageSha256,
            'ocr_line_count' => count($ocrPayload['lines'] ?? []),
            'ocr_overall_confidence' => $ocrPayload['overall_confidence'] ?? null,
            'result_status' => 'success',
            'image_preprocess' => $prepMeta,
        ]);

        return $this->responseBuilder->success($normalized, $ruleResult, [], $confidence, $usage);
    }

    /**
     * @return array<string,mixed>
     */
    private function detectText(string $content): array
    {
        if ((string) config('ocr.provider', 'google_vision') === 'gemini') {
            return $this->geminiCompanyClient->detectText($content);
        }

        return $this->ocrClient->detectText($content);
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     */
    private function normalize(array $raw): array
    {
        $phone = $this->normalizer->normalizePhone($raw['phone'] ?? null, $raw['country_code'] ?? null);

        return [
            'company_name' => $this->normalizer->normalizeCompanyName($raw['company_name'] ?? null),
            'company_type' => $this->normalizer->normalizeCompanyType($raw['company_type'] ?? null),
            'ssm_number' => $this->normalizer->normalizeSsmNumber($raw['ssm_number'] ?? null),
            'tin_number' => $this->normalizer->normalizeTinNumber($raw['tin_number'] ?? null),
            'sst_number' => $this->normalizer->normalizeSstNumber($raw['sst_number'] ?? null),
            'msic_codes' => $this->normalizer->normalizeMsicCodes(is_array($raw['msic_codes'] ?? null) ? $raw['msic_codes'] : []),
            'phone' => $phone['phone'],
            'country_code' => $phone['country_code'],
            'email' => $this->normalizer->normalizeEmail($raw['email'] ?? null),
            'address_line_1' => $this->normalizer->normalizeText($raw['address_line_1'] ?? null),
            'address_line_2' => $this->normalizer->normalizeText($raw['address_line_2'] ?? null),
            'address_line_3' => $this->normalizer->normalizeText($raw['address_line_3'] ?? null),
            'postcode' => $this->normalizer->normalizePostcode($raw['postcode'] ?? null),
            'city' => $this->normalizer->normalizeText($raw['city'] ?? null),
            'state' => $this->normalizer->normalizeText($raw['state'] ?? null),
            'country' => $this->normalizer->normalizeText($raw['country'] ?? null),
            'lhdn_employer_no' => $this->normalizer->normalizeLhdnEmployerNo($raw['lhdn_employer_no'] ?? null),
            'epf_employer_no' => $this->normalizer->normalizeEmployerNumber($raw['epf_employer_no'] ?? null),
            'socso_employer_no' => $this->normalizer->normalizeEmployerNumber($raw['socso_employer_no'] ?? null),
            'hrdc_employer_no' => $this->normalizer->normalizeEmployerNumber($raw['hrdc_employer_no'] ?? null),
            'zakat_employer_no' => $this->normalizer->normalizeEmployerNumber($raw['zakat_employer_no'] ?? null),
            'jtk_employer_no' => $this->normalizer->normalizeEmployerNumber($raw['jtk_employer_no'] ?? null),
        ];
    }
}
