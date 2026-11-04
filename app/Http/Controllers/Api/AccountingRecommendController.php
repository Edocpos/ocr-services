<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecommendAccountingRequest;
use App\Services\AccountingOcr\StructuredAccountingRecommendation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AccountingRecommendController extends Controller
{
    public function __invoke(RecommendAccountingRequest $request, StructuredAccountingRecommendation $service): JsonResponse
    {
        $requestId = (string) Str::uuid();
        @set_time_limit((int) config('ocr.request_max_execution_seconds', 120));
        try {
            return response()->json($service->recommend($request->validated()), 200, [], JSON_PRESERVE_ZERO_FRACTION);
        } catch (Throwable $exception) {
            $code = $exception->getMessage();
            Log::error('accounting_recommendation_failed', [
                'request_id' => $requestId,
                'reason' => $code,
                'exception' => $exception::class,
            ]);
            [$status, $errorCode, $message] = match (true) {
                $code === 'ocr_upstream_timeout' => [504, 'ocr_upstream_timeout', 'The accounting provider timed out.'],
                $code === 'ocr_provider_model_unavailable' => [502, 'ocr_provider_model_unavailable', 'The configured Gemini model is unavailable.'],
                $code === 'ocr_provider_auth_failed', $code === 'ocr_config_error: GEMINI_API_KEY is not set.' => [503, 'ocr_provider_configuration_error', 'The accounting provider credentials are unavailable.'],
                $code === 'ocr_provider_rate_limited' => [503, 'ocr_provider_rate_limited', 'The accounting provider is temporarily rate limited.'],
                $code === 'ocr_provider_request_rejected' => [502, 'ocr_provider_request_rejected', 'The accounting provider rejected the request.'],
                $code === 'ocr_upstream_error' || str_starts_with($code, 'ocr_upstream_error:') => [502, 'ocr_upstream_error', 'The accounting provider could not return a valid response.'],
                str_starts_with($code, 'structured_accounting_') => [502, 'accounting_proposal_invalid', 'The accounting provider returned a journal proposal that failed validation.'],
                default => [503, 'accounting_recommendation_unavailable', 'The accounting recommendation service is temporarily unavailable.'],
            };

            return response()->json([
                'message' => $message,
                'error_code' => $errorCode,
                'request_id' => $requestId,
            ], $status);
        }
    }
}
