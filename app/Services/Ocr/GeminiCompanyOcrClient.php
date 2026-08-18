<?php

namespace App\Services\Ocr;

use App\Support\OcrDocumentMime;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Gemini Vision extractor for Malaysian company registration documents
 * (SSM certificates, company profiles, SST / TIN letters).
 */
class GeminiCompanyOcrClient
{
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    private const PROMPT = <<<'PROMPT'
You are reading a Malaysian company document. Typical sources include SSM certificates of incorporation, SSM company profiles (Profil Syarikat), Form 9 / Section 17, SST certificates, LHDN TIN or employer letters, KWSP/EPF employer registration, PERKESO/SOCSO letters, HRD Corp MyCoID letters, JTK, and Zakat/PPZ employer documents.

Extract the company details and return them as a JSON object with exactly these fields:

{
  "company_name": "LEGAL COMPANY NAME",
  "company_type": "sdn_bhd",
  "ssm_number": "202301012345",
  "tin_number": "C1234567890",
  "sst_number": "W10-1901-32000001",
  "msic_codes": ["62010"],
  "phone": "123456789",
  "country_code": "+60",
  "email": "info@company.com",
  "address_line_1": "No 1, Jalan Example",
  "address_line_2": "Tingkat 5, Menara ABC",
  "address_line_3": null,
  "postcode": "50450",
  "city": "Kuala Lumpur",
  "state": "Wilayah Persekutuan",
  "country": "Malaysia",
  "lhdn_employer_no": "E12345678901",
  "epf_employer_no": "1234567",
  "socso_employer_no": "123456789012",
  "hrdc_employer_no": "123456789012345",
  "zakat_employer_no": "EMP123456",
  "jtk_employer_no": "123456789012",
  "confidence": 0.95
}

Rules:
- company_name: Legal registered name only. Ignore SSM headers, watermarks, and certificate titles.
- company_type: One of sole_proprietor, partnership, llp, sdn_bhd, bhd. Infer from the name suffix or document wording (Sendirian Berhad / Sdn Bhd = sdn_bhd, Berhad / Bhd = bhd, LLP / PLT = llp, Perkongsian = partnership, Perusahaan Persendirian / Enterprise / Sole Prop = sole_proprietor).
- ssm_number: Company registration / SSM number (new 12-digit format or older 6-7 digit plus letter suffix).
- tin_number: Tax identification number (TIN / No. Cukai Pendapatan). Keep the letter prefix if present. Do not confuse TIN with the LHDN employer number.
- sst_number: Sales and Service Tax number if printed (often W##-####-########).
- msic_codes: Up to 3 five-digit MSIC activity codes. Return [] if none are visible.
- phone: Local number without country code. country_code should be "+60" for Malaysian numbers.
- email: Company email if printed.
- Split the registered address into address_line_1, address_line_2, address_line_3. Put postcode, city, state, and country in their own fields. Do not repeat postcode/city/state inside the address lines.
- lhdn_employer_no: LHDN / PCB employer file number (often starts with E). Not the company TIN.
- epf_employer_no: KWSP / EPF employer number.
- socso_employer_no: SOCSO / PERKESO employer number. If EIS uses the same number, return that value here.
- hrdc_employer_no: HRD Corp MyCoID.
- zakat_employer_no: Zakat / PPZ employer number.
- jtk_employer_no: JTK (Jabatan Tenaga Kerja) employer number.
- confidence: Overall extraction confidence from 0.0 to 1.0.
- Return null for any field that cannot be clearly determined. Do not guess.
- Return ONLY the JSON object.
PROMPT;

    /**
     * @return array{
     *   full_text:string,
     *   lines:array<int,string>,
     *   overall_confidence:?float,
     *   pre_extracted:array<string,mixed>,
     *   usage:array{prompt_tokens:int,completion_tokens:int,total_tokens:int}
     * }
     */
    public function detectText(string $imageContent): array
    {
        $apiKey = (string) config('ocr.gemini_api_key');
        $model = (string) config('ocr.gemini_model', 'gemini-1.5-flash');
        $timeout = (int) config('ocr.gemini_timeout_seconds', 30);

        if ($apiKey === '') {
            throw new RuntimeException('ocr_config_error: GEMINI_API_KEY is not set.');
        }

        $url = sprintf(self::API_BASE, $model).'?key='.$apiKey;
        $mimeType = OcrDocumentMime::detect($imageContent);
        $base64 = base64_encode($imageContent);

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout((int) config('ocr.gemini_connect_timeout_seconds', 8))
                ->retry(
                    (int) config('ocr.gemini_retry_times', 1),
                    (int) config('ocr.gemini_retry_sleep_ms', 500)
                )
                ->post($url, [
                    'contents' => [
                        [
                            'parts' => [
                                [
                                    'inlineData' => [
                                        'mimeType' => $mimeType,
                                        'data' => $base64,
                                    ],
                                ],
                                ['text' => self::PROMPT],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => 0,
                        'responseMimeType' => 'application/json',
                        'responseSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'company_name' => ['type' => 'string', 'nullable' => true],
                                'company_type' => ['type' => 'string', 'nullable' => true],
                                'ssm_number' => ['type' => 'string', 'nullable' => true],
                                'tin_number' => ['type' => 'string', 'nullable' => true],
                                'sst_number' => ['type' => 'string', 'nullable' => true],
                                'msic_codes' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'string'],
                                ],
                                'phone' => ['type' => 'string', 'nullable' => true],
                                'country_code' => ['type' => 'string', 'nullable' => true],
                                'email' => ['type' => 'string', 'nullable' => true],
                                'address_line_1' => ['type' => 'string', 'nullable' => true],
                                'address_line_2' => ['type' => 'string', 'nullable' => true],
                                'address_line_3' => ['type' => 'string', 'nullable' => true],
                                'postcode' => ['type' => 'string', 'nullable' => true],
                                'city' => ['type' => 'string', 'nullable' => true],
                                'state' => ['type' => 'string', 'nullable' => true],
                                'country' => ['type' => 'string', 'nullable' => true],
                                'lhdn_employer_no' => ['type' => 'string', 'nullable' => true],
                                'epf_employer_no' => ['type' => 'string', 'nullable' => true],
                                'socso_employer_no' => ['type' => 'string', 'nullable' => true],
                                'hrdc_employer_no' => ['type' => 'string', 'nullable' => true],
                                'zakat_employer_no' => ['type' => 'string', 'nullable' => true],
                                'jtk_employer_no' => ['type' => 'string', 'nullable' => true],
                                'confidence' => ['type' => 'number'],
                            ],
                            'required' => ['confidence', 'msic_codes'],
                        ],
                    ],
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('ocr_upstream_timeout: Gemini API connection failed: '.$e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            $status = $e->response?->status() ?? 0;
            $body = (string) ($e->response?->body() ?? $e->getMessage());
            $bodyLower = strtolower($body);

            if ($status === 404 && (
                str_contains($bodyLower, 'no longer available to new users')
                || str_contains($bodyLower, 'is not found for api version')
                || str_contains($bodyLower, 'not found')
            )) {
                throw new RuntimeException(
                    'ocr_provider_model_unavailable: Gemini model "'.$model.'" is unavailable for this API key. '
                    .'Set OCR_GEMINI_MODEL to an available model (for example gemini-1.5-flash).',
                    0,
                    $e
                );
            }

            if ($status === 429) {
                throw new RuntimeException('ocr_upstream_timeout: Gemini API rate limit reached. Try again in a moment.', 0, $e);
            }

            if ($status === 413) {
                throw new RuntimeException('ocr_image_too_large_for_provider: Image is too large for Gemini API.', 0, $e);
            }

            if ($status === 400 && str_contains($bodyLower, 'unable to process input image')) {
                throw new RuntimeException(
                    'ocr_invalid_document: Gemini could not read this file. Please upload a JPG or PNG, or a standard PDF of the document.',
                    0,
                    $e
                );
            }

            throw new RuntimeException(
                'ocr_upstream_error: Gemini API returned HTTP '
                .$status.': '
                .substr($body, 0, 300),
                0,
                $e
            );
        }

        if ($response->status() === 429) {
            throw new RuntimeException('ocr_upstream_timeout: Gemini API rate limit reached. Try again in a moment.');
        }

        if ($response->status() === 413) {
            throw new RuntimeException('ocr_image_too_large_for_provider: Image is too large for Gemini API.');
        }

        if (! $response->successful()) {
            throw new RuntimeException(
                'ocr_upstream_error: Gemini API returned HTTP '
                .$response->status().': '
                .substr($response->body(), 0, 300)
            );
        }

        $body = $response->json();

        $usageMetadata = is_array($body['usageMetadata'] ?? null) ? $body['usageMetadata'] : [];
        $promptTokens = is_numeric($usageMetadata['promptTokenCount'] ?? null)
            ? (int) $usageMetadata['promptTokenCount']
            : 0;
        $completionTokens = is_numeric($usageMetadata['candidatesTokenCount'] ?? null)
            ? (int) $usageMetadata['candidatesTokenCount']
            : 0;
        $totalTokens = is_numeric($usageMetadata['totalTokenCount'] ?? null)
            ? (int) $usageMetadata['totalTokenCount']
            : ($promptTokens + $completionTokens);

        $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if ($text === null || $text === '') {
            $finishReason = $body['candidates'][0]['finishReason'] ?? 'unknown';
            throw new RuntimeException("ocr_upstream_error: Gemini returned no content. finishReason={$finishReason}");
        }

        $extracted = json_decode($text, true);

        if (! is_array($extracted)) {
            if (preg_match('/\{.*\}/s', $text, $m) === 1) {
                $extracted = json_decode($m[0], true);
            }
        }

        if (! is_array($extracted)) {
            throw new RuntimeException('ocr_upstream_error: Gemini response could not be parsed as JSON. Raw: '.substr($text, 0, 200));
        }

        $nullableString = static function (mixed $value): ?string {
            if (! is_string($value) && ! is_numeric($value)) {
                return null;
            }

            $trimmed = trim((string) $value);

            return $trimmed !== '' ? $trimmed : null;
        };

        $msicCodes = [];
        if (is_array($extracted['msic_codes'] ?? null)) {
            foreach ($extracted['msic_codes'] as $code) {
                $normalized = $nullableString($code);
                if ($normalized !== null) {
                    $msicCodes[] = $normalized;
                }
            }
        }

        $preExtracted = [
            'company_name' => $nullableString($extracted['company_name'] ?? null),
            'company_type' => $nullableString($extracted['company_type'] ?? null),
            'ssm_number' => $nullableString($extracted['ssm_number'] ?? null),
            'tin_number' => $nullableString($extracted['tin_number'] ?? null),
            'sst_number' => $nullableString($extracted['sst_number'] ?? null),
            'msic_codes' => $msicCodes,
            'phone' => $nullableString($extracted['phone'] ?? null),
            'country_code' => $nullableString($extracted['country_code'] ?? null),
            'email' => $nullableString($extracted['email'] ?? null),
            'address_line_1' => $nullableString($extracted['address_line_1'] ?? null),
            'address_line_2' => $nullableString($extracted['address_line_2'] ?? null),
            'address_line_3' => $nullableString($extracted['address_line_3'] ?? null),
            'postcode' => $nullableString($extracted['postcode'] ?? null),
            'city' => $nullableString($extracted['city'] ?? null),
            'state' => $nullableString($extracted['state'] ?? null),
            'country' => $nullableString($extracted['country'] ?? null),
            'lhdn_employer_no' => $nullableString($extracted['lhdn_employer_no'] ?? null),
            'epf_employer_no' => $nullableString($extracted['epf_employer_no'] ?? null),
            'socso_employer_no' => $nullableString($extracted['socso_employer_no'] ?? null),
            'hrdc_employer_no' => $nullableString($extracted['hrdc_employer_no'] ?? null),
            'zakat_employer_no' => $nullableString($extracted['zakat_employer_no'] ?? null),
            'jtk_employer_no' => $nullableString($extracted['jtk_employer_no'] ?? null),
        ];

        $confidence = isset($extracted['confidence']) && is_numeric($extracted['confidence'])
            ? (float) $extracted['confidence']
            : null;

        $fullText = implode("\n", array_filter([
            $preExtracted['company_name'],
            $preExtracted['ssm_number'],
            $preExtracted['tin_number'],
            $preExtracted['sst_number'],
            $preExtracted['address_line_1'],
            $preExtracted['address_line_2'],
            $preExtracted['postcode'],
            $preExtracted['city'],
            $preExtracted['state'],
            $preExtracted['lhdn_employer_no'],
            $preExtracted['epf_employer_no'],
            $preExtracted['socso_employer_no'],
            $preExtracted['hrdc_employer_no'],
            $preExtracted['zakat_employer_no'],
            $preExtracted['jtk_employer_no'],
        ], static fn (?string $value): bool => $value !== null && $value !== ''));

        return [
            'full_text' => $fullText,
            'lines' => array_values(array_filter(explode("\n", $fullText))),
            'overall_confidence' => $confidence,
            'pre_extracted' => $preExtracted,
            'usage' => [
                'prompt_tokens' => max(0, $promptTokens),
                'completion_tokens' => max(0, $completionTokens),
                'total_tokens' => max(0, $totalTokens),
            ],
        ];
    }
}
