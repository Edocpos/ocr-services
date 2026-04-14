<?php

namespace App\Services\Ocr;

use App\Contracts\Ocr\OcrClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google Gemini Vision OCR client for Malaysian MyKad extraction.
 *
 * Unlike raw Google Vision (which returns an unstructured flat list of text lines),
 * Gemini understands the visual layout of the card and returns the three IC fields
 * (ic_number, name, address) directly as structured JSON — no PHP heuristics needed.
 *
 * The response is packed into the standard OcrClient contract with an additional
 * `pre_extracted` key. IcFieldExtractor detects this key and bypasses all heuristic
 * parsing, using the Gemini-returned values directly.
 */
class GeminiIcOcrClient implements OcrClient
{
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    /**
     * Structured prompt instructing Gemini to act as a MyKad field extractor.
     * Temperature is set to 0 so responses are deterministic.
     */
    private const PROMPT = <<<'PROMPT'
You are reading a Malaysian MyKad (national identity card image).

Extract exactly these three data fields and return them as a JSON object:

{
  "ic_number": "XXXXXX-XX-XXXX",
  "name": "FULL NAME",
  "address": "full address",
  "confidence": 0.95
}

Rules:
- ic_number: The 12-digit ID number formatted as XXXXXX-XX-XXXX (6 digits, dash, 2 digits, dash, 4 digits). It appears in large font near the top of the card below the header.
- name: The person's full legal name only. It appears below the IC number. Join lines that belong to the same name (e.g. "ROWAN SEBASTIAN" on one line and "ATKINSON" on the next → "ROWAN SEBASTIAN ATKINSON"). IGNORE: the "MyKad" logo/brand, "KAD PENGENALAN MALAYSIA" header, "JABATAN PENDAFTARAN NEGARA", "NEGARA KHUNSA" watermark, signature text, decorative text, and the Malaysian flag graphic.
- address: The home address printed below the name. Assemble all address lines (street, building, postcode, city, state) into one string separated by ", ". Do NOT include the person's name or IC number in the address.
- confidence: Your overall confidence in the extraction from 0.0 (cannot read the card at all) to 1.0 (perfectly legible card, fully certain of all fields).
- Return null for any field that cannot be clearly determined.
- Return ONLY the JSON object. No markdown, no explanation, no code blocks.
PROMPT;

    public function detectText(string $imageContent): array
    {
        $apiKey  = (string) config('ocr.gemini_api_key');
        $model   = (string) config('ocr.gemini_model', 'gemini-1.5-flash');
        $timeout = (int) config('ocr.gemini_timeout_seconds', 30);

        if ($apiKey === '') {
            throw new RuntimeException('ocr_config_error: GEMINI_API_KEY is not set.');
        }

        $url      = sprintf(self::API_BASE, $model) . '?key=' . $apiKey;
        $mimeType = $this->detectMimeType($imageContent);
        $base64   = base64_encode($imageContent);

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
                                        'data'     => $base64,
                                    ],
                                ],
                                ['text' => self::PROMPT],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'temperature'      => 0,
                        'responseMimeType' => 'application/json',
                        'responseSchema'   => [
                            'type'       => 'object',
                            'properties' => [
                                'ic_number'  => ['type' => 'string',  'nullable' => true],
                                'name'       => ['type' => 'string',  'nullable' => true],
                                'address'    => ['type' => 'string',  'nullable' => true],
                                'confidence' => ['type' => 'number'],
                            ],
                            'required' => ['confidence'],
                        ],
                    ],
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('ocr_upstream_timeout: Gemini API connection failed: ' . $e->getMessage(), 0, $e);
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

            throw new RuntimeException(
                'ocr_upstream_error: Gemini API returned HTTP '
                . $status . ': '
                . substr($body, 0, 300),
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
                . $response->status() . ': '
                . substr($response->body(), 0, 300)
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

        // Gemini returns text in candidates[0].content.parts[0].text
        $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if ($text === null || $text === '') {
            $finishReason = $body['candidates'][0]['finishReason'] ?? 'unknown';
            throw new RuntimeException("ocr_upstream_error: Gemini returned no content. finishReason={$finishReason}");
        }

        // Parse JSON — responseMimeType forces valid JSON so this should always succeed.
        $extracted = json_decode($text, true);

        if (! is_array($extracted)) {
            // Fallback: try to extract JSON substring if somehow wrapped in text.
            if (preg_match('/\{.*\}/s', $text, $m) === 1) {
                $extracted = json_decode($m[0], true);
            }
        }

        if (! is_array($extracted)) {
            throw new RuntimeException('ocr_upstream_error: Gemini response could not be parsed as JSON. Raw: ' . substr($text, 0, 200));
        }

        $icNumber  = isset($extracted['ic_number'])  && $extracted['ic_number']  !== '' ? (string) $extracted['ic_number']  : null;
        $name      = isset($extracted['name'])       && $extracted['name']       !== '' ? (string) $extracted['name']       : null;
        $address   = isset($extracted['address'])    && $extracted['address']    !== '' ? (string) $extracted['address']    : null;
        $confidence = isset($extracted['confidence']) && is_numeric($extracted['confidence'])
            ? (float) $extracted['confidence']
            : null;

        // Build a synthetic full_text for logging and downstream compatibility.
        $fullText = implode("\n", array_filter([$icNumber, $name, $address]));

        return [
            'full_text'         => $fullText,
            'lines'             => array_filter(explode("\n", $fullText)),
            'overall_confidence' => $confidence,

            // Pre-extracted fields: IcFieldExtractor detects this key and returns
            // them directly, bypassing all PHP heuristic parsing.
            'pre_extracted' => [
                'ic_number' => $icNumber,
                'name'      => $name,
                'address'   => $address,
            ],
            'usage' => [
                'prompt_tokens' => max(0, $promptTokens),
                'completion_tokens' => max(0, $completionTokens),
                'total_tokens' => max(0, $totalTokens),
            ],
        ];
    }

    /**
     * Detect MIME type from image binary magic bytes.
     */
    private function detectMimeType(string $content): string
    {
        $header = substr($content, 0, 12);

        if (str_starts_with($header, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }

        if (str_starts_with($header, "\x89PNG")) {
            return 'image/png';
        }

        if (str_starts_with($header, 'RIFF') && substr($header, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return 'image/jpeg'; // safe default for unknown types
    }
}
