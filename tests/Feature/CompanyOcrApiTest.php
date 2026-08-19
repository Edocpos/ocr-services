<?php

namespace Tests\Feature;

use App\Contracts\Ocr\OcrClient;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CompanyOcrApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['ocr.provider' => 'google_vision']);
    }

    private function fakePngImage(): UploadedFile
    {
        $png1x1 = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+Z2ioAAAAASUVORK5CYII=');

        return UploadedFile::fake()->createWithContent('ssm.png', $png1x1 ?: 'x');
    }

    public function test_it_returns_structured_company_fields_from_ssm_text(): void
    {
        $this->app->bind(OcrClient::class, fn () => new class implements OcrClient
        {
            public function detectText(string $imageContent): array
            {
                return [
                    'full_text' => implode("\n", [
                        'SURUHANJAYA SYARIKAT MALAYSIA',
                        'NAMA SYARIKAT: Template Demo Sdn Bhd',
                        'NO. SYARIKAT: 202600000999',
                        'TIN: C1234567890',
                        'SST: W10-1901-32000001',
                        'MSIC CODE: 62010',
                        'ALAMAT: No 1, Jalan Template, Tingkat 5, 50450 Kuala Lumpur, Wilayah Persekutuan',
                        'TEL: 03-12345678',
                        'EMAIL: company@example.com',
                    ]),
                    'lines' => [
                        'SURUHANJAYA SYARIKAT MALAYSIA',
                        'NAMA SYARIKAT: Template Demo Sdn Bhd',
                        'NO. SYARIKAT: 202600000999',
                        'TIN: C1234567890',
                        'SST: W10-1901-32000001',
                        'MSIC CODE: 62010',
                        'ALAMAT: No 1, Jalan Template, Tingkat 5, 50450 Kuala Lumpur, Wilayah Persekutuan',
                        'TEL: 03-12345678',
                        'EMAIL: company@example.com',
                    ],
                    'overall_confidence' => 0.93,
                ];
            }
        });

        $response = $this->post('/api/ocr/company', [
            'image' => $this->fakePngImage(),
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('data.extracted.company_name', 'Template Demo Sdn Bhd')
            ->assertJsonPath('data.extracted.company_type', 'sdn_bhd')
            ->assertJsonPath('data.extracted.ssm_number', '202600000999')
            ->assertJsonPath('data.extracted.tin_number', 'C1234567890')
            ->assertJsonPath('data.extracted.sst_number', 'W10-1901-32000001')
            ->assertJsonPath('data.extracted.msic_codes.0', '62010')
            ->assertJsonPath('data.extracted.email', 'company@example.com')
            ->assertJsonPath('data.extracted.postcode', '50450')
            ->assertJsonPath('data.extracted.address_line_1', 'No 1, Jalan Template')
            ->assertJsonPath('meta.document_type', 'company');
    }

    public function test_it_uses_pre_extracted_gemini_company_fields(): void
    {
        $this->app->bind(OcrClient::class, fn () => new class implements OcrClient
        {
            public function detectText(string $imageContent): array
            {
                return [
                    'full_text' => 'ACME CORPORATION SDN BHD',
                    'lines' => ['ACME CORPORATION SDN BHD'],
                    'overall_confidence' => 0.97,
                    'pre_extracted' => [
                        'company_name' => 'Acme Corporation Sdn Bhd',
                        'company_type' => 'sdn_bhd',
                        'ssm_number' => '202301012345',
                        'tin_number' => 'C9876543210',
                        'sst_number' => null,
                        'msic_codes' => ['62010', '62021'],
                        'phone' => '0123456789',
                        'country_code' => '+60',
                        'email' => 'info@acme.com',
                        'address_line_1' => 'No 10, Jalan Tun Razak',
                        'address_line_2' => 'Tingkat 5, Menara ABC',
                        'address_line_3' => null,
                        'postcode' => '50450',
                        'city' => 'Kuala Lumpur',
                        'state' => 'Wilayah Persekutuan',
                        'country' => 'Malaysia',
                        'lhdn_employer_no' => 'E12345678901',
                        'epf_employer_no' => '1234567',
                        'socso_employer_no' => '123456789012',
                        'hrdc_employer_no' => '123456789012345',
                        'zakat_employer_no' => 'EMP123456',
                        'jtk_employer_no' => '123456789012',
                    ],
                ];
            }
        });

        $response = $this->post('/api/ocr/company', [
            'image' => $this->fakePngImage(),
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('validation.status', 'ok')
            ->assertJsonPath('data.extracted.company_name', 'Acme Corporation Sdn Bhd')
            ->assertJsonPath('data.extracted.ssm_number', '202301012345')
            ->assertJsonPath('data.extracted.phone', '123456789')
            ->assertJsonPath('data.extracted.country_code', '+60')
            ->assertJsonPath('data.extracted.msic_codes.1', '62021')
            ->assertJsonPath('data.extracted.lhdn_employer_no', 'E12345678901')
            ->assertJsonPath('data.extracted.epf_employer_no', '1234567')
            ->assertJsonPath('data.extracted.socso_employer_no', '123456789012')
            ->assertJsonPath('data.extracted.hrdc_employer_no', '123456789012345')
            ->assertJsonPath('data.extracted.zakat_employer_no', 'EMP123456')
            ->assertJsonPath('data.extracted.jtk_employer_no', '123456789012')
            ->assertJsonPath('data.derived.company_type', 'sdn_bhd');
    }

    public function test_it_extracts_statutory_employer_numbers_without_confusing_them_with_tin(): void
    {
        $this->app->bind(OcrClient::class, fn () => new class implements OcrClient
        {
            public function detectText(string $imageContent): array
            {
                return [
                    'full_text' => implode("\n", [
                        'NAMA SYARIKAT: Template Demo Sdn Bhd',
                        'NO. SYARIKAT: 202600000999',
                        'TIN: C1234567890',
                        'LHDN Employer No.: E12345678901',
                        'EPF Employer No.: 1234567',
                        'SOCSO / PERKESO Employer No.: 123456789012',
                        'HRDC MyCoID: 123456789012345',
                        'Zakat / PPZ Employer No.: EMP123456',
                        'JTK Employer No.: 987654321098',
                    ]),
                    'lines' => [
                        'NAMA SYARIKAT: Template Demo Sdn Bhd',
                        'NO. SYARIKAT: 202600000999',
                        'TIN: C1234567890',
                        'LHDN Employer No.: E12345678901',
                        'EPF Employer No.: 1234567',
                        'SOCSO / PERKESO Employer No.: 123456789012',
                        'HRDC MyCoID: 123456789012345',
                        'Zakat / PPZ Employer No.: EMP123456',
                        'JTK Employer No.: 987654321098',
                    ],
                    'overall_confidence' => 0.94,
                ];
            }
        });

        $response = $this->post('/api/ocr/company', [
            'image' => $this->fakePngImage(),
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('data.extracted.tin_number', 'C1234567890')
            ->assertJsonPath('data.extracted.lhdn_employer_no', 'E12345678901')
            ->assertJsonPath('data.extracted.epf_employer_no', '1234567')
            ->assertJsonPath('data.extracted.socso_employer_no', '123456789012')
            ->assertJsonPath('data.extracted.hrdc_employer_no', '123456789012345')
            ->assertJsonPath('data.extracted.zakat_employer_no', 'EMP123456')
            ->assertJsonPath('data.extracted.jtk_employer_no', '987654321098');
    }

    public function test_it_blocks_result_when_confidence_is_low(): void
    {
        $this->app->bind(OcrClient::class, fn () => new class implements OcrClient
        {
            public function detectText(string $imageContent): array
            {
                return [
                    'full_text' => 'NAMA SYARIKAT: Template Demo Sdn Bhd',
                    'lines' => ['NAMA SYARIKAT: Template Demo Sdn Bhd'],
                    'overall_confidence' => 0.20,
                ];
            }
        });

        $response = $this->post('/api/ocr/company', [
            'image' => $this->fakePngImage(),
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('validation.status', 'failed')
            ->assertJsonPath('validation.errors.0.code', 'low_confidence_image')
            ->assertJsonPath('data.extracted.company_name', null);
    }

    public function test_it_returns_partial_when_only_some_company_fields_are_readable(): void
    {
        $this->app->bind(OcrClient::class, fn () => new class implements OcrClient
        {
            public function detectText(string $imageContent): array
            {
                return [
                    'full_text' => "NAMA SYARIKAT: Template Demo Sdn Bhd\nNO. SYARIKAT: 202600000999",
                    'lines' => [
                        'NAMA SYARIKAT: Template Demo Sdn Bhd',
                        'NO. SYARIKAT: 202600000999',
                    ],
                    'overall_confidence' => 0.88,
                ];
            }
        });

        $response = $this->post('/api/ocr/company', [
            'image' => $this->fakePngImage(),
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('data.extracted.company_name', 'Template Demo Sdn Bhd')
            ->assertJsonPath('data.extracted.ssm_number', '202600000999')
            ->assertJsonPath('data.extracted.email', null);
    }

    public function test_it_accepts_pdf_documents_for_company_ocr(): void
    {
        $this->app->bind(OcrClient::class, fn () => new class implements OcrClient
        {
            public function detectText(string $imageContent): array
            {
                return [
                    'full_text' => 'NAMA SYARIKAT: Template Demo Sdn Bhd',
                    'lines' => ['NAMA SYARIKAT: Template Demo Sdn Bhd'],
                    'overall_confidence' => 0.91,
                    'pre_extracted' => [
                        'company_name' => 'Template Demo Sdn Bhd',
                        'company_type' => 'sdn_bhd',
                        'ssm_number' => '202600000999',
                        'tin_number' => null,
                        'sst_number' => null,
                        'msic_codes' => [],
                        'phone' => null,
                        'country_code' => null,
                        'email' => null,
                        'address_line_1' => null,
                        'address_line_2' => null,
                        'address_line_3' => null,
                        'postcode' => null,
                        'city' => null,
                        'state' => null,
                        'country' => null,
                    ],
                ];
            }
        });

        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";

        $response = $this->post('/api/ocr/company', [
            'image' => UploadedFile::fake()->createWithContent('ssm.pdf', $pdf),
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('data.extracted.company_name', 'Template Demo Sdn Bhd')
            ->assertJsonPath('data.extracted.ssm_number', '202600000999');
    }

    public function test_it_extracts_a_local_trading_license_without_requiring_ssm(): void
    {
        $this->app->bind(OcrClient::class, fn () => new class implements OcrClient
        {
            public function detectText(string $imageContent): array
            {
                return [
                    'full_text' => implode("\n", [
                        'LESEN PERNIAGAAN TEMPATAN',
                        'NAMA SYARIKAT: Sabah Trading Enterprise',
                        'TRADING LICENSE NO: DBKK-TL-88991',
                        'ISSUING AUTHORITY: Dewan Bandaraya Kota Kinabalu',
                        'EXPIRY: 2026-12-31',
                        'TIN: C1234567890',
                    ]),
                    'lines' => [
                        'LESEN PERNIAGAAN TEMPATAN',
                        'NAMA SYARIKAT: Sabah Trading Enterprise',
                        'TRADING LICENSE NO: DBKK-TL-88991',
                        'ISSUING AUTHORITY: Dewan Bandaraya Kota Kinabalu',
                        'EXPIRY: 2026-12-31',
                        'TIN: C1234567890',
                    ],
                    'overall_confidence' => 0.91,
                ];
            }
        });

        $response = $this->post('/api/ocr/company', [
            'image' => $this->fakePngImage(),
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('data.extracted.company_name', 'Sabah Trading Enterprise')
            ->assertJsonPath('data.extracted.local_trading_license', 'DBKK-TL-88991')
            ->assertJsonPath('data.extracted.local_trading_license_issuer', 'Dewan Bandaraya Kota Kinabalu')
            ->assertJsonPath('data.extracted.local_trading_license_expires_on', '2026-12-31');
    }
}
