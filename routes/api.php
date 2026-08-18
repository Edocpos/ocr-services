<?php

use App\Http\Controllers\Api\CompanyOcrController;
use App\Http\Controllers\Api\IcOcrController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:ocr-ic')->group(function (): void {
    Route::post('/ocr/ic', IcOcrController::class);
});

Route::middleware('throttle:ocr-company')->group(function (): void {
    Route::post('/ocr/company', CompanyOcrController::class);
});
