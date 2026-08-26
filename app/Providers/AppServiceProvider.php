<?php

namespace App\Providers;

use App\Contracts\Ocr\OcrClient;
use App\Services\Ocr\GeminiIcOcrClient;
use App\Services\Ocr\GoogleVisionOcrClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(OcrClient::class, function (): OcrClient {
            return match ((string) config('ocr.provider', 'google_vision')) {
                'gemini' => new GeminiIcOcrClient,
                default => new GoogleVisionOcrClient,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('ocr-ic', function (Request $request): Limit {
            return Limit::perMinute((int) config('ocr.rate_limit_per_minute', 30))
                ->by($request->ip() ?: 'unknown');
        });

        RateLimiter::for('ocr-company', function (Request $request): Limit {
            return Limit::perMinute((int) config('ocr.rate_limit_per_minute', 30))
                ->by($request->ip() ?: 'unknown');
        });

        RateLimiter::for('ocr-statutory', function (Request $request): Limit {
            return Limit::perMinute((int) config('ocr.statutory_rate_limit_per_minute', config('ocr.rate_limit_per_minute', 30)))
                ->by($request->ip() ?: 'unknown');
        });
    }
}
