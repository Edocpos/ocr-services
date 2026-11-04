<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecommendAccountingRequest;
use App\Services\AccountingOcr\StructuredAccountingRecommendation;
use Illuminate\Http\JsonResponse;
use Throwable;

class AccountingRecommendController extends Controller
{
    public function __invoke(RecommendAccountingRequest $request, StructuredAccountingRecommendation $service): JsonResponse
    {
        @set_time_limit((int) config('ocr.request_max_execution_seconds', 120));
        try {
            return response()->json($service->recommend($request->validated()), 200, [], JSON_PRESERVE_ZERO_FRACTION);
        } catch (Throwable $exception) {
            report($exception);
            $timeout = str_contains($exception->getMessage(), 'ocr_upstream_timeout');

            return response()->json([
                'message' => $timeout ? 'The accounting provider timed out.' : 'The accounting recommendation service is temporarily unavailable.',
                'error_code' => $timeout ? 'ocr_upstream_timeout' : 'accounting_recommendation_unavailable',
            ], $timeout ? 504 : 503);
        }
    }
}
