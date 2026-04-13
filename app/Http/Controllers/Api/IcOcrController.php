<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProcessIcOcrRequest;
use App\Services\IcOcr\IcOcrPipeline;
use App\Services\IcOcr\IcResponseBuilder;
use App\Services\IcOcr\OcrUsageEstimator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class IcOcrController extends Controller
{
    public function __invoke(ProcessIcOcrRequest $request, IcOcrPipeline $pipeline, IcResponseBuilder $responseBuilder, OcrUsageEstimator $usageEstimator)
    {
        $requestId = (string) Str::uuid();
        Log::withContext([
            'request_id' => $requestId,
            'client_ip' => $request->ip(),
            'user_agent_sha256' => hash('sha256', (string) $request->userAgent()),
        ]);

        @set_time_limit((int) config('ocr.request_max_execution_seconds', 120));

        try {
            $result = $pipeline->process($request->file('image'));

            if (isset($result['meta']) && is_array($result['meta'])) {
                $result['meta']['request_id'] = $requestId;
            }

            return response()->json($result);
        } catch (Throwable $exception) {
            report($exception);

            $message = $exception->getMessage();
            $status = 502;
            $code = 'ocr_processing_error';
            $userMessage = 'OCR processing failed for this request.';

            if (str_contains($message, 'ocr_upstream_timeout')) {
                $status = 504;
                $code = 'ocr_upstream_timeout';
                $userMessage = 'OCR provider timed out. Please upload a smaller or clearer image and try again.';
            } elseif (str_contains($message, 'ocr_provider_model_unavailable')) {
                $status = 502;
                $code = 'ocr_provider_model_unavailable';
                $userMessage = 'Configured Gemini model is unavailable for this API key. Please switch to an available model.';
            } elseif (str_contains($message, 'ocr_image_too_large_for_provider')) {
                $status = 422;
                $code = 'ocr_image_too_large_for_provider';
                $userMessage = 'Image is too large for OCR provider processing. Please upload a smaller image.';
            }

            $failure = $responseBuilder->failure($code, $userMessage, $usageEstimator->estimate(1));
            if (isset($failure['meta']) && is_array($failure['meta'])) {
                $failure['meta']['request_id'] = $requestId;
            }

            return response()->json(
                $failure,
                $status
            );
        }
    }
}
