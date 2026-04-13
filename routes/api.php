<?php

use App\Http\Controllers\Api\IcOcrController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:ocr-ic')->group(function (): void {
    Route::post('/ocr/ic', IcOcrController::class);
});
