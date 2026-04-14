<?php

use App\Http\Controllers\Docs\ApiDocsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/docs', [ApiDocsController::class, 'index'])->name('docs.index');
Route::post('/docs/unlock', [ApiDocsController::class, 'unlock'])->name('docs.unlock');
Route::post('/docs/logout', [ApiDocsController::class, 'logout'])->name('docs.logout');
