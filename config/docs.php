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
            [
                'id' => 'company-ocr',
                'name' => 'Company OCR',
                'description' => 'Extract Malaysian company registration details from SSM certificates, company profiles, SST certificates, and TIN letters.',
                'endpoints' => [
                    [
                        'id' => 'post-ocr-company',
                        'title' => 'Process Company OCR',
                        'method' => 'POST',
                        'path' => '/api/ocr/company',
                        'tag' => 'OCR',
                        'content_type' => 'multipart/form-data',
                        'rate_limit' => ':rate_limit requests/minute/IP',
                        'request_fields' => [
                            ['field' => 'image', 'type' => 'file', 'required' => true, 'notes' => 'Max :max_file_size_kb KB. SSM / company document image or PDF.'],
                        ],
                        'curl_example' => "curl --location ':app_url/api/ocr/company' \\\n+  --form 'image=@\"/absolute/path/to/ssm.jpg\"'",
                        'responses' => [
                            [
                                'label' => '200 OK — Successful extraction',
                                'status' => 200,
                                'json' => [
                                    'data' => [
                                        'extracted' => [
                                            'company_name' => 'Template Demo Sdn Bhd',
                                            'company_type' => 'sdn_bhd',
                                            'ssm_number' => '202600000999',
                                            'local_trading_license' => null,
                                            'local_trading_license_issuer' => null,
                                            'local_trading_license_expires_on' => null,
                                            'tin_number' => 'C1234567890',
                                            'sst_number' => 'W10-1901-32000001',
                                            'msic_codes' => ['62010'],
                                            'phone' => '12345678',
                                            'country_code' => '+60',
                                            'email' => 'company@example.com',
                                            'address_line_1' => 'No 1, Jalan Template',
                                            'address_line_2' => 'Tingkat 5',
                                            'address_line_3' => null,
                                            'postcode' => '50450',
                                            'city' => 'Kuala Lumpur',
                                            'state' => 'Wilayah Persekutuan',
                                            'country' => 'Malaysia',
                                        ],
                                        'derived' => [
                                            'company_type' => 'sdn_bhd',
                                            'city' => 'Kuala Lumpur',
                                            'state' => 'Wilayah Persekutuan',
                                            'country' => 'Malaysia',
                                        ],
                                    ],
                                    'validation' => [
                                        'status' => 'ok',
                                        'errors' => [],
                                        'warnings' => [],
                                    ],
                                    'meta' => [
                                        'provider' => ':ocr_provider',
                                        'document_type' => 'company',
                                        'stateless' => true,
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
            [
                'id' => 'statutory-ocr',
                'name' => 'Statutory receipt OCR',
                'description' => 'Extract Malaysian statutory payment-receipt fields for EPF, SOCSO, EIS, PCB and HRDC. Each scheme has a dedicated endpoint and parser. PCB supports LHDN e-PCB letters, confirmation slips, and CP 6A official receipts.',
                'endpoints' => [
                    [
                        'id' => 'post-ocr-statutory',
                        'title' => 'Process statutory receipt OCR',
                        'method' => 'POST',
                        'path' => '/api/ocr/{epf|socso|eis|pcb|hrdc}',
                        'tag' => 'OCR',
                        'content_type' => 'multipart/form-data',
                        'rate_limit' => ':rate_limit requests/minute/IP',
                        'request_fields' => [
                            ['field' => 'image', 'type' => 'file', 'required' => true, 'notes' => 'PDF, JPG, PNG or WebP. Max 10 MB. MIME is verified from contents.'],
                            ['field' => 'scheme', 'type' => 'string', 'required' => true, 'notes' => 'Must match the endpoint scheme.'],
                        ],
                        'curl_example' => "curl --location ':app_url/api/ocr/socso' \\\n  --form 'image=@\"/absolute/path/to/receipt.pdf\"' \\\n  --form 'scheme=\"socso\"'",
                        'responses' => [
                            [
                                'label' => '200 OK — Successful extraction',
                                'status' => 200,
                                'json' => [
                                    'data' => [
                                        'extracted' => [
                                            'receipt_number' => '20260004540969',
                                            'payment_date' => '06/08/2026',
                                            'contribution_period' => '07/2026',
                                            'contribution_reference' => 'ACR082260152714',
                                            'employer_number' => 'F9702103813F',
                                            'employer_name' => 'EDOCPOS SDN . BHD.',
                                            'transaction_id' => '2608061236580909',
                                            'bank' => 'Public Bank Berhad',
                                            'amount' => 318.45,
                                            'payment_description' => '1.Caruman Bulanan(ACR082260152714-07/2026)(RM318.45)',
                                        ],
                                    ],
                                    'meta' => [
                                        'scheme' => 'socso',
                                        'confidence' => 0.98,
                                        'field_confidence' => [
                                            'receipt_number' => 0.99,
                                            'amount' => 0.99,
                                        ],
                                        'requires_manual_review' => false,
                                        'warnings' => [],
                                    ],
                                ],
                            ],
                            [
                                'label' => '422 — Scheme mismatch',
                                'status' => 422,
                                'json' => [
                                    'message' => 'This appears to be an EIS receipt, not a SOCSO receipt.',
                                    'error_code' => 'receipt_scheme_mismatch',
                                    'data' => [
                                        'detected_scheme' => 'eis',
                                        'expected_scheme' => 'socso',
                                    ],
                                ],
                            ],
                        ],
                        'error_codes' => [
                            ['code' => 'receipt_scheme_mismatch', 'http' => 422, 'note' => 'The file is a different statutory scheme than the endpoint.'],
                            ['code' => 'receipt_requires_manual_review', 'http' => 422, 'note' => 'The receipt could not be read reliably.'],
                            ['code' => 'rate_limited', 'http' => 429, 'note' => 'Too many statutory OCR requests from this IP.'],
                            ['code' => 'ocr_unavailable', 'http' => 503, 'note' => 'Temporary OCR provider or processing failure.'],
                        ],
                    ],
                ],
            ],
        ],
    ],
];
