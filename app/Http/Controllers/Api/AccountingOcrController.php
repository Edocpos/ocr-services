<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AccountingOcrException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProcessAccountingOcrRequest;
use App\Services\AccountingOcr\AccountingOcrPipeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AccountingOcrController extends Controller
{
    public function __invoke(ProcessAccountingOcrRequest $request, AccountingOcrPipeline $pipeline): JsonResponse
    {
        $requestId = (string) Str::uuid();
        Log::withContext([
            'request_id' => $requestId,
            'client_ip' => $request->ip(),
            'user_agent_sha256' => hash('sha256', (string) $request->userAgent()),
            'document_type' => 'accounting',
        ]);

        @set_time_limit((int) config('ocr.request_max_execution_seconds', 120));

        try {
            return response()->json($pipeline->process(
                $request->file('document'),
                $request->accounts(),
                $requestId,
            ));
        } catch (AccountingOcrException $exception) {
            return response()->json($exception->toArray(), $exception->status);
        } catch (Throwable $exception) {
            report($exception);

            $message = $exception->getMessage();
            if (str_contains($message, 'ocr_upstream_timeout')) {
                return response()->json([
                    'message' => 'The OCR provider timed out. Please try again.',
                    'error_code' => 'ocr_upstream_timeout',
                ], 504);
            }
            if (str_contains($message, 'ocr_provider_model_unavailable')) {
                return response()->json([
                    'message' => 'The configured Gemini model is unavailable.',
                    'error_code' => 'ocr_provider_model_unavailable',
                ], 502);
            }
            if (str_contains($message, 'ocr_image_too_large_for_provider')) {
                return response()->json([
                    'message' => 'The document is too large for OCR provider processing.',
                    'error_code' => 'ocr_image_too_large_for_provider',
                ], 422);
            }

            return response()->json(AccountingOcrException::unavailable()->toArray(), 503);
        }
    }
}
