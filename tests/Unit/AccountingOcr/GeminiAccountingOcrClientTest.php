<?php

namespace Tests\Unit\AccountingOcr;

use App\Services\Ocr\GeminiAccountingOcrClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GeminiAccountingOcrClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ocr.gemini_api_key' => 'test-key',
            'ocr.accounting_gemini_model' => 'test-model',
            'ocr.gemini_retry_times' => 1,
        ]);
    }

    public function test_it_parses_structured_output_and_token_usage(): void
    {
        $providerPayload = json_encode([
            'transaction_count' => 1,
            'overall_confidence' => 0.95,
            'lines' => [],
        ]);

        Http::fake(['*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => $providerPayload]]],
            ]],
            'usageMetadata' => [
                'promptTokenCount' => 120,
                'candidatesTokenCount' => 30,
                'totalTokenCount' => 150,
            ],
        ], 200)]);

        $result = (new GeminiAccountingOcrClient)->extract(
            'document bytes',
            'image/png',
            [['code' => 'BANK-1', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'bank']],
        );

        $this->assertSame(1, $result['transaction_count']);
        $this->assertSame(150, $result['usage']['total_tokens']);
        Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), 'test-model:generateContent')
            && str_contains((string) data_get($request->data(), 'contents.0.parts.1.text'), 'BANK-1'));
    }

    public function test_it_rejects_malformed_provider_json(): void
    {
        Http::fake(['*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'not-json']]]]],
        ], 200)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid accounting JSON');

        (new GeminiAccountingOcrClient)->extract('bytes', 'image/png', []);
    }

    public function test_it_maps_an_unavailable_model_error(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Model not found']], 404)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ocr_provider_model_unavailable');

        (new GeminiAccountingOcrClient)->extract('bytes', 'image/png', []);
    }

    public function test_it_maps_provider_timeouts(): void
    {
        Http::fake(['*' => Http::response([], 504)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ocr_upstream_timeout');

        (new GeminiAccountingOcrClient)->extract('bytes', 'image/png', []);
    }
}
