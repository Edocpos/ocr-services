<?php

use App\Http\Controllers\Api\CompanyOcrController;
use App\Http\Controllers\Api\IcOcrController;
use App\Http\Controllers\Api\StatutoryReceiptOcrController;
use App\Services\StatutoryOcr\StatutoryOcrPipeline;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:ocr-ic')->group(function (): void {
    Route::post('/ocr/ic', IcOcrController::class);
});

Route::middleware('throttle:ocr-company')->group(function (): void {
    Route::post('/ocr/company', CompanyOcrController::class);
});

Route::middleware('throttle:ocr-statutory')->group(function (): void {
    foreach (StatutoryOcrPipeline::SCHEMES as $scheme) {
        Route::post('/ocr/'.$scheme, StatutoryReceiptOcrController::class)
            ->defaults('scheme', $scheme);
    }
});
