<?php

namespace App\Services\IcOcr;

class OcrUsageEstimator
{
    /**
     * @return array{provider:string,unit:string,quantity:int,currency:string,price_per_unit_rm:float,estimated_cost_rm:float,is_estimated:bool,prompt_tokens?:int,completion_tokens?:int,total_tokens?:int}
     */
    public function estimate(int $quantity, ?array $providerUsage = null): array
    {
        $quantity = max(0, $quantity);
        $provider = (string) config('ocr.provider', 'google_vision');

        if ($provider === 'gemini' && is_array($providerUsage)) {
            $promptTokens = max(0, (int) ($providerUsage['prompt_tokens'] ?? 0));
            $completionTokens = max(0, (int) ($providerUsage['completion_tokens'] ?? 0));
            $totalTokens = max(0, (int) ($providerUsage['total_tokens'] ?? ($promptTokens + $completionTokens)));

            $inputRatePer1k = $this->geminiRatePer1kInRm('input');
            $outputRatePer1k = $this->geminiRatePer1kInRm('output');

            // Gemini API returns token usage metadata, but does not return monetary price directly.
            // We always return token-based usage when available. If token rates are not configured,
            // cost remains 0.0 to avoid falling back to unrelated per-image env rates.
            $estimatedCost = ($promptTokens / 1000) * $inputRatePer1k
                + ($completionTokens / 1000) * $outputRatePer1k;
            $pricePerToken = $totalTokens > 0 ? ($estimatedCost / $totalTokens) : 0.0;

            return [
                'provider' => $provider,
                'unit' => 'token',
                'quantity' => $totalTokens,
                'currency' => (string) config('ocr.usage_currency', 'MYR'),
                'price_per_unit_rm' => round($pricePerToken, 8),
                'estimated_cost_rm' => round($estimatedCost, 6),
                'is_estimated' => true,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
            ];
        }

        $pricePerUnit = $this->resolvePricePerUnit($provider);

        return [
            'provider' => $provider,
            'unit' => (string) config('ocr.usage_unit', 'image'),
            'quantity' => $quantity,
            'currency' => (string) config('ocr.usage_currency', 'MYR'),
            'price_per_unit_rm' => round($pricePerUnit, 6),
            'estimated_cost_rm' => round($pricePerUnit * $quantity, 6),
            'is_estimated' => true,
        ];
    }

    private function resolvePricePerUnit(string $provider): float
    {
        if ($provider === 'gemini') {
            $geminiPrice = (float) config('ocr.estimated_price_per_image_rm_gemini', 0.0);
            if ($geminiPrice > 0) {
                return $geminiPrice;
            }
        }

        if ($provider === 'google_vision') {
            $visionPrice = (float) config('ocr.estimated_price_per_image_rm_google_vision', 0.0);
            if ($visionPrice > 0) {
                return $visionPrice;
            }
        }

        return (float) config('ocr.estimated_price_per_image_rm', 0.0);
    }

    private function geminiRatePer1kInRm(string $kind): float
    {
        $directKey = $kind === 'input'
            ? 'ocr.estimated_price_per_1k_tokens_rm_gemini_input'
            : 'ocr.estimated_price_per_1k_tokens_rm_gemini_output';

        $direct = (float) config($directKey, 0.0);
        if ($direct > 0) {
            return $direct;
        }

        $usdPer1m = $kind === 'input'
            ? (float) config('ocr.gemini_input_usd_per_1m_tokens', 0.0)
            : (float) config('ocr.gemini_output_usd_per_1m_tokens', 0.0);
        $fx = (float) config('ocr.usd_to_myr_rate', 0.0);

        if ($usdPer1m <= 0 || $fx <= 0) {
            return 0.0;
        }

        // Convert USD per 1M tokens -> RM per 1K tokens
        return ($usdPer1m * $fx) / 1000;
    }
}
