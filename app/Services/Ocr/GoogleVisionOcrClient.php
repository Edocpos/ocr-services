<?php

namespace App\Services\Ocr;

use App\Contracts\Ocr\OcrClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleVisionOcrClient implements OcrClient
{
    public function detectText(string $imageContent): array
    {
        $apiKey = (string) config('services.google_vision.api_key');
        if ($apiKey !== '') {
            return $this->detectWithApiKey($imageContent, $apiKey);
        }

        if (! class_exists(\Google\Cloud\Vision\V1\ImageAnnotatorClient::class)) {
            throw new RuntimeException('Google Vision client is not installed and API key mode is not configured.');
        }

        $clientConfig = [];

        $credentials = (string) config('services.google_vision.credentials');
        if ($credentials !== '') {
            $clientConfig['credentials'] = $credentials;
        }

        $projectId = (string) config('services.google_vision.project_id');
        if ($projectId !== '') {
            $clientConfig['projectId'] = $projectId;
        }

        $client = new \Google\Cloud\Vision\V1\ImageAnnotatorClient($clientConfig);

        try {
            $response = $client->documentTextDetection($imageContent);

            $fullText = trim((string) ($response->getFullTextAnnotation()?->getText() ?? ''));
            if ($fullText === '') {
                $annotations = iterator_to_array($response->getTextAnnotations()->getIterator());
                if ($annotations !== []) {
                    $fullText = trim((string) ($annotations[0]->getDescription() ?? ''));
                }
            }

            $lines = $this->splitLines($fullText);
            $overallConfidence = $this->extractSdkOverallConfidence($response->getFullTextAnnotation());

            return [
                'full_text' => $fullText,
                'lines' => $lines,
                'overall_confidence' => $overallConfidence,
            ];
        } finally {
            $client->close();
        }
    }

    /**
    * @return array{full_text:string, lines:array<int,string>, overall_confidence:?float}
     */
    private function detectWithApiKey(string $imageContent, string $apiKey): array
    {
        $timeout = (int) config('ocr.vision_timeout_seconds', 20);
        $connectTimeout = (int) config('ocr.vision_connect_timeout_seconds', 5);
        $retryTimes = (int) config('ocr.vision_retry_times', 2);
        $retrySleepMs = (int) config('ocr.vision_retry_sleep_ms', 250);

        try {
            $response = Http::connectTimeout($connectTimeout)
                ->timeout($timeout)
                ->retry($retryTimes, $retrySleepMs, throw: false)
                ->post('https://vision.googleapis.com/v1/images:annotate?key='.$apiKey, [
                    'requests' => [[
                        'image' => [
                            'content' => base64_encode($imageContent),
                        ],
                        'features' => [[
                            'type' => 'DOCUMENT_TEXT_DETECTION',
                        ]],
                    ]],
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('ocr_upstream_timeout', 0, $exception);
        }

        if (! $response->successful()) {
            $status = $response->status();
            $body = substr((string) $response->body(), 0, 500);

            if ($status === 413) {
                throw new RuntimeException('ocr_image_too_large_for_provider', 0);
            }

            if ($status === 408 || $status === 504) {
                throw new RuntimeException('ocr_upstream_timeout', 0);
            }

            throw new RuntimeException('Google Vision API request failed with status '.$status.'. '.$body);
        }

        $payload = $response->json();
        $fullText = trim((string) data_get($payload, 'responses.0.fullTextAnnotation.text', ''));

        if ($fullText === '') {
            $fullText = trim((string) data_get($payload, 'responses.0.textAnnotations.0.description', ''));
        }

        if ($fullText === '') {
            return [
                'full_text' => '',
                'lines' => [],
                'overall_confidence' => null,
            ];
        }

        $lines = $this->splitLines($fullText);
        $overallConfidence = $this->extractRestOverallConfidence($payload);

        return [
            'full_text' => $fullText,
            'lines' => $lines,
            'overall_confidence' => $overallConfidence,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function splitLines(string $fullText): array
    {
        return array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            preg_split('/\R/u', $fullText) ?: []
        )));
    }

    private function extractSdkOverallConfidence(?object $fullTextAnnotation): ?float
    {
        if ($fullTextAnnotation === null || ! method_exists($fullTextAnnotation, 'getPages')) {
            return null;
        }

        $sum = 0.0;
        $count = 0;

        foreach ($fullTextAnnotation->getPages()->getIterator() as $page) {
            if (! method_exists($page, 'getBlocks')) {
                continue;
            }

            foreach ($page->getBlocks()->getIterator() as $block) {
                if (! method_exists($block, 'getConfidence')) {
                    continue;
                }

                $confidence = (float) $block->getConfidence();
                if ($confidence <= 0) {
                    continue;
                }

                $sum += $confidence;
                $count++;
            }
        }

        if ($count === 0) {
            return null;
        }

        return round($sum / $count, 4);
    }

    private function extractRestOverallConfidence(array $payload): ?float
    {
        $blocks = data_get($payload, 'responses.0.fullTextAnnotation.pages.0.blocks');
        if (! is_array($blocks)) {
            return null;
        }

        $sum = 0.0;
        $count = 0;

        foreach ($blocks as $block) {
            if (! is_array($block) || ! isset($block['confidence'])) {
                continue;
            }

            $confidence = (float) $block['confidence'];
            if ($confidence <= 0) {
                continue;
            }

            $sum += $confidence;
            $count++;
        }

        if ($count === 0) {
            return null;
        }

        return round($sum / $count, 4);
    }
}
