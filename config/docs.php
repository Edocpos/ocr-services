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
                                        'final_validation_required_by' => 'Arkcloudant',
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
                'description' => 'Extract Malaysian company registration details from SSM certificates, company profiles, SST certificates, and TIN letters. When the file is a company-register document, also return corporate_document so the caller can file it.',
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
                                            'address_line_1' => 'No 1, Jalan',
                                            'address_line_2' => 'Template Tingkat 5',
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
                                        'corporate_document' => [
                                            'document_type' => 'notice_of_registration',
                                            'document_date' => '2026-01-15',
                                            'effective_date' => null,
                                            'lodgement_date' => '2026-01-20',
                                            'ssm_reference' => null,
                                            'annual_return_year' => null,
                                        ],
                                    ],
                                    'validation' => [
                                        'status' => 'ok',
                                        'final_validation_required_by' => 'Arkcloudant',
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
                'id' => 'accounting-ocr',
                'name' => 'Accounting classification OCR',
                'description' => 'Classify one accounting document, match the submitted chart of accounts, and return a journal proposal with PDF-hierarchy account guidance, MSIC business context and prepayment review. Required accounting roles use canonical PDF prefixes; matched legacy codes are preserved. Missing roles return ACCOUNT_NOT_AVAILABLE plus advisory account recommendations. Arkcloudant performs final validation and posting.',
                'endpoints' => [
                    [
                        'id' => 'get-accounting-accounts', 'title' => 'Account category library',
                        'method' => 'GET', 'path' => '/api/ocr/accounting/accounts', 'tag' => 'Reference',
                        'content_type' => 'application/json', 'rate_limit' => ':accounting_rate_limit requests/minute/IP',
                        'request_fields' => [],
                        'curl_example' => "curl ':app_url/api/ocr/accounting/accounts'",
                        'responses' => [[
                            'label' => '200 OK — Full library (80 categories; abbreviated example)', 'status' => 200,
                            'json' => ['data' => ['version' => 'acc01-2.0-semantics-2', 'accounts' => [
                                'BS/CA/ORV/PRMT' => ['name' => 'PREPAYMENT', 'definition' => 'Payments made in advance for goods or services to be received in future.', 'usage_examples' => ['Annual insurance paid before coverage is consumed'], 'exclusions' => 'Exclude refundable security deposits and already consumed services; a billing period alone does not prove prepayment.', 'type' => 'asset', 'subtype' => 'prepayment', 'normal_balance' => 'debit', 'code' => 'BS/CA/ORV/PRMT', 'purpose' => 'Advance payments for future goods or service coverage', 'aliases' => ['Prepaid Expenses', 'Prepaid Insurance', 'Prepaid Subscription'], 'role' => 'prepayment', 'source_page' => 29],
                            ]]],
                        ]],
                        'error_codes' => [],
                    ],
                    [
                        'id' => 'post-ocr-accounting',
                        'title' => 'Process accounting OCR',
                        'method' => 'POST',
                        'path' => '/api/ocr/accounting',
                        'tag' => 'OCR',
                        'content_type' => 'multipart/form-data',
                        'rate_limit' => ':accounting_rate_limit requests/minute/IP',
                        'request_fields' => [
                            ['field' => 'document', 'type' => 'file', 'required' => true, 'notes' => 'PDF, JPG, PNG or WebP. Max 10 MB. One logical transaction.'],
                            ['field' => 'company', 'type' => 'JSON/string', 'required' => false, 'notes' => 'JSON company snapshot or free-form context, forwarded as supplied with no required schema. Used to determine document direction.'],
                            ['field' => 'msic_code', 'type' => 'string', 'required' => false, 'notes' => 'Primary company MSIC, exactly five digits including leading zeros; validated against the local catalogue. 00000 means not applicable.'],
                            ['field' => 'additional_msic_codes', 'type' => 'JSON array', 'required' => false, 'notes' => 'Up to 10 additional MSIC strings. Only selected codes and resolved descriptions are sent to AI.'],
                            ['field' => 'business_description', 'type' => 'string', 'required' => false, 'notes' => 'Actual business operations; max 2000 characters. Industry context does not determine posting by itself.'],
                            ['field' => 'posting_date', 'type' => 'YYYY-MM-DD', 'required' => false, 'notes' => 'Accounting date required before posting readiness. Used for coverage assessment; invoice/transaction/server dates are never substituted.'],
                            ['field' => 'accounts', 'type' => 'JSON array', 'required' => true, 'notes' => '0–500 accounts with code, name, type, subtype, and optional aliases, purpose (max 1000 characters), usage_examples (up to 3 strings, max 500 characters each). Optional role and acc01_prefix support custom codes. Prefer the named supplier/customer ledger by counterparty name or alias. Explicitly required AP/AR controls need is_control_account=true and control_role=ap/ar; an individual ledger cannot bypass a control requirement. Recognised prefixes derive authoritative roles from the source library. Subtypes also accept prepayment, supplier_advance, customer_advance, refundable_deposit, accrual, other_payable, income_tax_asset and income_tax_payable.'],
                        ],
                        'curl_example' => "curl --location ':app_url/api/ocr/accounting' \\\n  --form 'document=@\"/absolute/path/to/voucher.pdf\"' \\\n  --form 'company={\"name\":\"ACME SDN BHD\",\"registration_no\":\"202301012345\"}' \\\n  --form 'posting_date=2026-09-17' \\\n  --form 'accounts=[{\"code\":\"BS/CA/CNB/BANK/10000\",\"name\":\"Maybank\",\"type\":\"asset\",\"subtype\":\"bank\"}]'",
                        'responses' => [
                            ['label' => '200 OK — Compact journal proposal requiring review', 'status' => 200, 'json' => [
                                'data' => [
                                    'extracted' => [
                                        'transaction_date' => '2026-09-21',
                                        'reference_number' => 'SRI-INV-2026-0921-001',
                                        'counterparty' => 'SABAH RENOVATION & INTERIOR SDN BHD',
                                        'description' => 'Restaurant Renovation Works - Kitchen & Dining Area',
                                        'currency' => 'MYR',
                                    ],
                                    'classification' => [
                                        'voucher_type' => 'general_voucher',
                                    ],
                                    'journal_entry' => [
                                        'lines' => [
                                            [
                                                'account_code' => 'BS/NA/PPE/RENO/10000',
                                                'account_name' => 'RENOVATION',
                                                'description' => 'Restaurant renovation costs',
                                                'debit' => '12250.00',
                                                'credit' => '0.00',
                                                'confidence' => 0.9,
                                                'reason' => 'The renovation costs are proposed for capitalisation.',
                                                'evidence' => 'Invoice: Restaurant Renovation Works',
                                                'recommendation' => null,
                                            ],
                                            [
                                                'account_code' => null,
                                                'account_name' => 'SABAH RENOVATION & INTERIOR SDN BHD',
                                                'description' => 'Amount payable for restaurant renovation',
                                                'debit' => '0.00',
                                                'credit' => '12250.00',
                                                'confidence' => 1.0,
                                                'reason' => 'The invoice creates a non-trade payable to the contractor.',
                                                'evidence' => 'Invoice issued by Sabah Renovation & Interior Sdn Bhd',
                                                'recommendation' => [
                                                    'parent_definition_key' => 'OPCR',
                                                    'parent_code' => 'BS/CL/OPY/OPCR',
                                                    'account_type' => 'liability',
                                                    'account_subtype' => 'other_payable',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                'validation' => [
                                    'status' => 'review_required',
                                    'is_postable' => false,
                                    'requires_manual_review' => true,
                                    'warnings' => [
                                        [
                                            'code' => 'posting_date_required',
                                            'field' => 'posting_date',
                                            'message' => 'Arkcloudant must supply the accounting posting date before posting; invoice and event dates do not establish it.',
                                        ],
                                        [
                                            'code' => 'payment_status_unclear',
                                            'field' => 'payment_status',
                                            'message' => 'Payment is not established by document terms or bank details; confirm settlement status before posting.',
                                        ],
                                        [
                                            'code' => 'capitalisation_policy_review',
                                            'field' => 'capitalisation',
                                            'message' => 'PPE treatment is a proposal. Confirm improvements versus repairs, directly attributable costs and company capitalisation policy for each item before approval.',
                                        ],
                                    ],
                                    'errors' => [
                                        [
                                            'code' => 'ACCOUNT_NOT_AVAILABLE',
                                            'field' => 'journal_entry.lines[1]',
                                            'message' => 'No supplied account satisfies the required accounting classification and control role. Arkcloudant must resolve this requirement before posting.',
                                        ],
                                    ],
                                ],
                            ]],
                        ],
                        'error_codes' => [
                            ['code' => 'ACCOUNT_NOT_AVAILABLE', 'http' => 200, 'note' => 'Proposal blocked: required role/control missing; see the proposed account_name and recommendation category. No account created.'],
                            ['code' => 'ACCOUNT_SELECTION_INVALID', 'http' => 200, 'note' => 'Proposal blocked: selected account is not eligible; no automatic substitution.'],
                            ['code' => 'ACCOUNT_REQUIREMENT_INVALID', 'http' => 200, 'note' => 'Proposal blocked: missing or contradictory accounting requirement.'],
                            ['code' => 'ACCOUNT_TREATMENT_CONFLICT', 'http' => 200, 'note' => 'Proposal blocked: trade obligation assigned to Other Creditors.'],
                            ['code' => 'multiple_transactions_detected', 'http' => 422, 'note' => 'Upload one logical transaction per request.'],
                            ['code' => 'ocr_provider_model_unavailable', 'http' => 502, 'note' => 'Configured Gemini model is unavailable.'],
                            ['code' => 'ocr_upstream_timeout', 'http' => 504, 'note' => 'Gemini timed out.'],
                            ['code' => 'ocr_unavailable', 'http' => 503, 'note' => 'Temporary OCR processing failure.'],
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
