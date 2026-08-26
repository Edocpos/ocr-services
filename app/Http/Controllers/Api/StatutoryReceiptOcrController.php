<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\StatutoryReceiptException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProcessStatutoryReceiptOcrRequest;
use App\Services\StatutoryOcr\StatutoryOcrPipeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class StatutoryReceiptOcrController extends Controller
{
    public function __invoke(ProcessStatutoryReceiptOcrRequest $request, StatutoryOcrPipeline $pipeline): JsonResponse
    {
        $request->headers->set('Accept', 'application/json');
        $requestId = (string) Str::uuid();
        $scheme = $request->expectedScheme();

        Log::withContext([
            'request_id' => $requestId,
            'client_ip' => $request->ip(),
            'user_agent_sha256' => hash('sha256', (string) $request->userAgent()),
            'scheme' => $scheme,
        ]);

        @set_time_limit((int) config('ocr.request_max_execution_seconds', 120));

        try {
            return response()->json($pipeline->process($request->file('image'), $scheme, $requestId));
        } catch (StatutoryReceiptException $exception) {
            return response()->json($exception->toArray(), $exception->status);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(StatutoryReceiptException::unavailable()->toArray(), 503);
        }
    }
}
