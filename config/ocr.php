<?php

return [
    // OCR provider: 'google_vision' | 'gemini'
    'provider' => env('OCR_PROVIDER', 'google_vision'),

    'max_file_size_kb' => (int) env('OCR_MAX_FILE_SIZE_KB', 5120),

    'allow_webp' => filter_var(env('OCR_ALLOW_WEBP', false), FILTER_VALIDATE_BOOL),

    'rate_limit_per_minute' => (int) env('OCR_RATE_LIMIT_PER_MINUTE', 30),

    'postcode_map_path' => env('OCR_POSTCODE_MAP_PATH', storage_path('app/reference/malaysia_postcodes.csv')),

    'vision_timeout_seconds' => (int) env('OCR_VISION_TIMEOUT_SECONDS', 20),

    'vision_connect_timeout_seconds' => (int) env('OCR_VISION_CONNECT_TIMEOUT_SECONDS', 5),

    'vision_retry_times' => (int) env('OCR_VISION_RETRY_TIMES', 2),

    'vision_retry_sleep_ms' => (int) env('OCR_VISION_RETRY_SLEEP_MS', 250),

    'request_max_execution_seconds' => (int) env('OCR_REQUEST_MAX_EXECUTION_SECONDS', 120),

    'preprocess_enable' => filter_var(env('OCR_PREPROCESS_ENABLE', true), FILTER_VALIDATE_BOOL),

    'preprocess_max_dimension' => (int) env('OCR_PREPROCESS_MAX_DIMENSION', 2200),

    'preprocess_max_bytes_for_direct' => (int) env('OCR_PREPROCESS_MAX_BYTES_FOR_DIRECT', 3145728),

    'preprocess_jpeg_quality' => (int) env('OCR_PREPROCESS_JPEG_QUALITY', 80),

    'preprocess_png_compression' => (int) env('OCR_PREPROCESS_PNG_COMPRESSION', 6),

    'preprocess_webp_quality' => (int) env('OCR_PREPROCESS_WEBP_QUALITY', 80),

    'enable_confidence_gate' => filter_var(env('OCR_ENABLE_CONFIDENCE_GATE', true), FILTER_VALIDATE_BOOL),

    'confidence_min_overall' => (float) env('OCR_CONFIDENCE_MIN_OVERALL', 0.55),

    'confidence_min_field' => (float) env('OCR_CONFIDENCE_MIN_FIELD', 0.50),

    'usage_currency' => env('OCR_USAGE_CURRENCY', 'MYR'),

    'usage_unit' => env('OCR_USAGE_UNIT', 'image'),

    // Backward-compatible global fallback price (used if provider-specific value is not set).
    'estimated_price_per_image_rm' => (float) env('OCR_ESTIMATED_PRICE_PER_IMAGE_RM', 0.00),

    // Provider-specific estimated prices in RM per image request.
    'estimated_price_per_image_rm_google_vision' => (float) env('OCR_ESTIMATED_PRICE_PER_IMAGE_RM_GOOGLE_VISION', 0.00),
    'estimated_price_per_image_rm_gemini' => (float) env('OCR_ESTIMATED_PRICE_PER_IMAGE_RM_GEMINI', 0.00),

    // Gemini estimated prices in RM per 1K tokens (input/output).
    // If set (>0) and token usage metadata is returned by Gemini, usage is computed by tokens.
    'estimated_price_per_1k_tokens_rm_gemini_input' => (float) env('OCR_ESTIMATED_PRICE_PER_1K_TOKENS_RM_GEMINI_INPUT', 0.00),
    'estimated_price_per_1k_tokens_rm_gemini_output' => (float) env('OCR_ESTIMATED_PRICE_PER_1K_TOKENS_RM_GEMINI_OUTPUT', 0.00),

    // Optional: Gemini official pricing in USD per 1M tokens + FX rate.
    // Used only when RM-per-1K keys above are not set (>0).
    'gemini_input_usd_per_1m_tokens' => (float) env('OCR_GEMINI_INPUT_USD_PER_1M_TOKENS', 0.00),
    'gemini_output_usd_per_1m_tokens' => (float) env('OCR_GEMINI_OUTPUT_USD_PER_1M_TOKENS', 0.00),
    'usd_to_myr_rate' => (float) env('OCR_USD_TO_MYR_RATE', 0.00),

    // Gemini Vision provider config
    'gemini_api_key'         => env('GEMINI_API_KEY'),
    'gemini_model'           => env('OCR_GEMINI_MODEL', 'gemini-2.5-flash'),
    'gemini_timeout_seconds' => (int) env('OCR_GEMINI_TIMEOUT_SECONDS', 30),
    'gemini_connect_timeout_seconds' => (int) env('OCR_GEMINI_CONNECT_TIMEOUT_SECONDS', env('OCR_VISION_CONNECT_TIMEOUT_SECONDS', 8)),
    'gemini_retry_times' => (int) env('OCR_GEMINI_RETRY_TIMES', env('OCR_VISION_RETRY_TIMES', 1)),
    'gemini_retry_sleep_ms' => (int) env('OCR_GEMINI_RETRY_SLEEP_MS', env('OCR_VISION_RETRY_SLEEP_MS', 500)),
];
