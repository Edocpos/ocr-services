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
            ->assertJsonPath('data.derived.company_type', 'sdn_bhd');
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
}
