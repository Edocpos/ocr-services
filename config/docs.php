<?php

return [
    'password' => env('DOCS_PASSWORD', 'EDP2026'),

    'content' => [
        'title' => 'OCR API Documentation',
        'subtitle' => 'Internal API reference. Designed for scalable multi-module documentation.',
        'version' => 'v1',
        'base_url' => ':app_url',
        'auth' => [
            'type' => 'None (current phase)',
            'note' => 'Route-level auth can be added later without changing docs structure.',
        ],
        'modules' => [
            [
                'id' => 'ic-ocr',
                'name' => 'IC OCR',
                'description' => 'Extract Malaysian IC fields from an uploaded image and derive deterministic values from IC number.',
                'endpoints' => [
                    [
                        'id' => 'post-ocr-ic',
                        'title' => 'Process IC OCR',
                        'method' => 'POST',
                        'path' => '/api/ocr/ic',
                        'tag' => 'OCR',
                        'content_type' => 'multipart/form-data',
                        'rate_limit' => ':rate_limit requests/minute/IP',
                        'request_fields' => [
                            ['field' => 'image', 'type' => 'file', 'required' => true, 'notes' => 'Max :max_file_size_kb KB'],
                        ],
                        'curl_example' => "curl --location ':app_url/api/ocr/ic' \\\n+  --form 'image=@\"/absolute/path/to/ic.jpg\"'",
                        'responses' => [
                            [
                                'label' => '200 OK — Successful extraction',
                                'status' => 200,
                                'json' => [
                                    'data' => [
                                        'extracted' => [
                                            'ic_number' => '550106-12-5821',
                                            'name' => 'ROWAN SEBASTIAN ATKINSON',
                                            'address' => 'GDW KAMPUNG BAYANGAN, 80000 KENINGAU, SABAH',
                                        ],
                                        'derived' => [
                                            'birth_date' => '1955-01-06',
                                            'gender' => 'male',
                                            'state' => 'Sabah',
                                            'district' => null,
                                        ],
                                    ],
                                    'validation' => [
                                        'status' => 'ok',
                                        'errors' => [],
                                        'warnings' => [],
                                    ],
                                    'confidence' => [
                                        'overall' => 0.95,
                                        'ic_number' => 1.0,
                                        'name' => 1.0,
                                        'address' => 1.0,
                                        'birth_date' => 1.0,
                                        'gender' => 1.0,
                                        'state' => 1.0,
                                        'district' => 1.0,
                                    ],
                                    'usage' => [
                                        'provider' => ':ocr_provider',
                                        'unit' => 'token',
                                        'quantity' => 1018,
                                        'currency' => 'MYR',
                                        'price_per_unit_rm' => 0.0000017,
                                        'estimated_cost_rm' => 0.001733,
                                        'is_estimated' => true,
                                        'prompt_tokens' => 654,
                                        'completion_tokens' => 69,
                                        'total_tokens' => 1018,
                                    ],
                                    'meta' => [
                                        'provider' => ':ocr_provider',
                                        'stateless' => true,
                                        'request_id' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
                                    ],
                                ],
                            ],
                            [
                                'label' => '200 — Low confidence blocked',
                                'status' => 200,
                                'json' => [
                                    'validation' => [
                                        'status' => 'failed',
                                        'errors' => [
                                            [
                                                'field' => 'image',
                                                'code' => 'low_confidence_image',
                                                'message' => 'Image confidence is too low. Please re-upload a clearer photo.',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            [
                                'label' => '502/504 — Upstream or processing failure',
                                'status' => 502,
                                'json' => [
                                    'validation' => [
                                        'status' => 'failed',
                                        'errors' => [
                                            [
                                                'field' => 'system',
                                                'code' => 'ocr_processing_error',
                                                'message' => 'OCR processing failed for this request.',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'error_codes' => [
                            ['code' => 'low_confidence_image', 'http' => 200, 'note' => 'Image accepted but result blocked by confidence gate.'],
                            ['code' => 'ocr_upstream_timeout', 'http' => 504, 'note' => 'Upstream provider timeout.'],
                            ['code' => 'ocr_image_too_large_for_provider', 'http' => 422, 'note' => 'Image too large for provider processing.'],
                            ['code' => 'ocr_processing_error', 'http' => 502, 'note' => 'Unhandled OCR pipeline failure.'],
                        ],
                    ],
                ],
            ],
        ],
    ],
];
