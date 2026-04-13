<?php

namespace Tests\Feature;

use App\Contracts\Ocr\OcrClient;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class IcOcrApiTest extends TestCase
{
    private function fakePngImage(): UploadedFile
    {
        $png1x1 = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7Z2ioAAAAASUVORK5CYII=');

        return UploadedFile::fake()->createWithContent('ic.png', $png1x1 ?: 'x');
    }

    public function test_it_returns_structured_response_for_valid_ic_data(): void
    {
        $this->app->bind(OcrClient::class, fn () => new class implements OcrClient {
            public function detectText(string $imageContent): array
            {
                return [
                    'full_text' => "900101-12-1235\nNAMA: John Doe\nALAMAT: 123 Jalan Test\nKuala Lumpur",
                    'lines' => [
                        '900101-12-1235',
                        'NAMA: John Doe',
                        'ALAMAT: 123 Jalan Test',
                        'Kuala Lumpur',
                    ],
                    'overall_confidence' => 0.92,
                ];
            }
        });

        $response = $this->post('/api/ocr/ic', [
            'image' => $this->fakePngImage(),
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('data.extracted.ic_number', '900101-12-1235')
            ->assertJsonPath('data.extracted.name', 'JOHN DOE')
            ->assertJsonPath('data.derived.birth_date', '1990-01-01')
            ->assertJsonPath('data.derived.gender', 'male');
    }

    public function test_it_nulls_derived_fields_when_ic_birth_date_is_invalid(): void
    {
        $this->app->bind(OcrClient::class, fn () => new class implements OcrClient {
            public function detectText(string $imageContent): array
            {
                return [
                    'full_text' => "990230-12-1234\nNAMA: Jane Doe\nALAMAT: Test Address",
                    'lines' => [
                        '990230-12-1234',
                        'NAMA: Jane Doe',
                        'ALAMAT: Test Address',
                    ],
                    'overall_confidence' => 0.88,
                ];
            }
        });

        $response = $this->post('/api/ocr/ic', [
            'image' => $this->fakePngImage(),
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('data.extracted.ic_number', '990230-12-1234')
            ->assertJsonPath('data.derived.birth_date', null)
            ->assertJsonPath('data.derived.gender', null)
            ->assertJsonPath('validation.status', 'partial');
    }

    public function test_it_accepts_non_image_file_type_when_validation_is_relaxed(): void
    {
        $this->app->bind(OcrClient::class, fn () => new class implements OcrClient {
            public function detectText(string $imageContent): array
            {
                return [
                    'full_text' => "900101-12-1235\nNAMA: JOHN DOE\nNO 1 JALAN TEST\n50000 KUALA LUMPUR",
                    'lines' => [
                        '900101-12-1235',
                        'NAMA: JOHN DOE',
                        'NO 1 JALAN TEST',
                        '50000 KUALA LUMPUR',
                    ],
                    'overall_confidence' => 0.90,
                ];
            }
        });

        $response = $this->post('/api/ocr/ic', [
            'image' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertOk();
    }

    public function test_it_derives_district_from_postcode_map(): void
    {
        $this->app->bind(OcrClient::class, fn () => new class implements OcrClient {
            public function detectText(string $imageContent): array
            {
                return [
                    'full_text' => "900101-09-1235\nNAMA: Ali Bin Abu\nNo 1 Jalan Test\n01000 Kangar Perlis\nJANTINA: L",
                    'lines' => [
                        '900101-09-1235',
                        'NAMA: Ali Bin Abu',
                        'No 1 Jalan Test',
                        '01000 Kangar Perlis',
                        'JANTINA: L',
                    ],
                    'overall_confidence' => 0.90,
                ];
            }
        });

        $response = $this->post('/api/ocr/ic', [
            'image' => $this->fakePngImage(),
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('data.derived.state', 'Perlis')
            ->assertJsonPath('data.derived.district', 'KANGAR');
    }

    public function test_it_blocks_result_when_confidence_is_low(): void
    {
        $this->app->bind(OcrClient::class, fn () => new class implements OcrClient {
            public function detectText(string $imageContent): array
            {
                return [
                    'full_text' => "900101-12-1235\nNAMA: John Doe\nNo 1 Jalan Test\n50000 Kuala Lumpur",
                    'lines' => [
                        '900101-12-1235',
                        'NAMA: John Doe',
                        'No 1 Jalan Test',
                        '50000 Kuala Lumpur',
                    ],
                    'overall_confidence' => 0.25,
                ];
            }
        });

        $response = $this->post('/api/ocr/ic', [
            'image' => $this->fakePngImage(),
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('data.extracted.ic_number', null)
            ->assertJsonPath('data.derived.birth_date', null)
            ->assertJsonPath('validation.status', 'failed')
            ->assertJsonPath('validation.errors.0.code', 'low_confidence_image');
    }
}
