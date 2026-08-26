<?php

namespace Tests\Feature;

use App\Contracts\Ocr\OcrClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class StatutoryReceiptOcrApiTest extends TestCase
{
    private function png(): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+Z2ioAAAAASUVORK5CYII=');

        return UploadedFile::fake()->createWithContent('receipt.png', $png ?: 'x');
    }

    private function pdfFixture(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            (string) file_get_contents(base_path('tests/Fixtures/receipts/'.$name))
        );
    }

    private function bindOcrText(string $text, ?float $confidence = 0.92): void
    {
        $this->app->bind(OcrClient::class, fn () => new class($text, $confidence) implements OcrClient
        {
            public function __construct(private readonly string $text, private readonly ?float $confidence) {}

            public function detectText(string $imageContent): array
            {
                return [
                    'full_text' => $this->text,
                    'lines' => preg_split('/\n/', $this->text) ?: [],
                    'overall_confidence' => $this->confidence,
                ];
            }
        });
    }

    public function test_it_extracts_the_eis_sample_receipt(): void
    {
        $response = $this->post('/api/ocr/eis', [
            'image' => $this->pdfFixture('eis.pdf'),
            'scheme' => 'eis',
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('data.extracted.receipt_number', '2026E0003785051')
            ->assertJsonPath('data.extracted.payment_date', '06/08/2026')
            ->assertJsonPath('data.extracted.contribution_period', '07/2026')
            ->assertJsonPath('data.extracted.contribution_reference', 'ECR082260127652')
            ->assertJsonPath('data.extracted.employer_number', 'F9702103813F')
            ->assertJsonPath('data.extracted.employer_name', 'EDOCPOS SDN . BHD.')
            ->assertJsonPath('data.extracted.bank', 'Public Bank Berhad')
            ->assertJsonPath('data.extracted.transaction_id', '2608061236580909')
            ->assertJsonPath('data.extracted.amount', 50)
            ->assertJsonPath('meta.scheme', 'eis')
            ->assertJsonPath('meta.requires_manual_review', false)
            ->assertJsonPath('meta.source', 'embedded_text');

        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertStringNotContainsString('<html', $response->getContent());
    }

    public function test_it_extracts_the_socso_sample_receipt(): void
    {
        $this->post('/api/ocr/socso', [
            'image' => $this->pdfFixture('socso.pdf'),
            'scheme' => 'socso',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.extracted.receipt_number', '20260004540969')
            ->assertJsonPath('data.extracted.contribution_reference', 'ACR082260152714')
            ->assertJsonPath('data.extracted.contribution_period', '07/2026')
            ->assertJsonPath('data.extracted.employer_number', 'F9702103813F')
            ->assertJsonPath('data.extracted.employer_name', 'EDOCPOS SDN . BHD.')
            ->assertJsonPath('data.extracted.bank', 'Public Bank Berhad')
            ->assertJsonPath('data.extracted.amount', 318.45)
            ->assertJsonPath('meta.scheme', 'socso')
            ->assertJsonPath('meta.requires_manual_review', false);
    }

    public function test_it_rejects_an_eis_receipt_on_the_socso_endpoint(): void
    {
        $this->post('/api/ocr/socso', [
            'image' => $this->pdfFixture('eis.pdf'),
            'scheme' => 'socso',
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'receipt_scheme_mismatch')
            ->assertJsonPath('data.detected_scheme', 'eis')
            ->assertJsonPath('data.expected_scheme', 'socso')
            ->assertJsonPath('message', 'This appears to be an EIS receipt, not a SOCSO receipt.');
    }

    public function test_it_rejects_a_socso_receipt_on_the_eis_endpoint(): void
    {
        $this->post('/api/ocr/eis', [
            'image' => $this->pdfFixture('socso.pdf'),
            'scheme' => 'eis',
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'receipt_scheme_mismatch')
            ->assertJsonPath('data.detected_scheme', 'socso')
            ->assertJsonPath('data.expected_scheme', 'eis');
    }

    public function test_it_rejects_invalid_mime_types(): void
    {
        $this->post('/api/ocr/eis', [
            'image' => UploadedFile::fake()->createWithContent('receipt.exe', 'MZ not an image'),
            'scheme' => 'eis',
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The uploaded receipt is invalid.')
            ->assertJsonPath('errors.image.0', 'The image must be a PDF, JPG, PNG or WebP file.');
    }

    public function test_it_rejects_oversized_uploads(): void
    {
        config(['ocr.statutory_max_file_size_kb' => 10240]);

        $this->post('/api/ocr/eis', [
            'image' => UploadedFile::fake()->createWithContent('huge.pdf', '%PDF-1.4\n'.str_repeat('A', 11 * 1024 * 1024)),
            'scheme' => 'eis',
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The uploaded receipt is invalid.')
            ->assertJsonStructure(['errors' => ['image']]);
    }

    public function test_it_rejects_a_missing_file(): void
    {
        $this->post('/api/ocr/eis', [
            'scheme' => 'eis',
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.image.0', 'The image field is required.');
    }

    public function test_it_rejects_a_corrupt_pdf(): void
    {
        $this->bindOcrText('');

        $this->post('/api/ocr/socso', [
            'image' => UploadedFile::fake()->createWithContent('broken.pdf', "%PDF-1.4\nthis is not a readable receipt"),
            'scheme' => 'socso',
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'receipt_requires_manual_review');
    }

    public function test_it_falls_back_to_ocr_for_image_only_pdfs(): void
    {
        $this->bindOcrText(implode("\n", [
            'RESIT RASMI No. Resit : 20260004540969',
            'Tarikh Bayaran : 06/08/2026',
            'Jenis Bayaran : 1.Caruman Bulanan(ACR082260152714-07/2026)(RM318.45)',
            'Kod Majikan : F9702103813F',
            'Nama Majikan : EDOCPOS SDN. BHD.',
            'FPX Transaksi ID : 2608061236580909',
            'Bank : Public Bank Berhad',
            'Jumlah Bayaran : RM318.45',
        ]));

        $imageOnlyPdf = "%PDF-1.4\n1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj\n2 0 obj<< /Type /Pages /Kids [3 0 R] /Count 1 >>endobj\n3 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] /Contents 4 0 R >>endobj\n4 0 obj<< /Length 0 >>stream\nendstream\nendobj\ntrailer<< /Root 1 0 R >>\n%%EOF";

        $this->post('/api/ocr/socso', [
            'image' => UploadedFile::fake()->createWithContent('image-only.pdf', $imageOnlyPdf),
            'scheme' => 'socso',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.extracted.receipt_number', '20260004540969')
            ->assertJsonPath('meta.source', 'ocr');
    }

    public function test_it_extracts_a_rotated_receipt_from_ocr_text(): void
    {
        $this->bindOcrText(implode("\n", [
            'RESIT RASMI No. Resit : 2026E0003785051',
            'Tarikh Bayaran : 06/08/2026',
            'Jenis Bayaran : Caruman Bulanan(ECR082260127652-07/2026)(RM50.00)',
            'Kod Majikan : F9702103813F',
            'Nama Majikan : EDOCPOS SDN. BHD.',
            'FPX Transaksi ID : 2608061236580909',
            'Bank : Public Bank Berhad',
            'Jumlah Bayaran : RM50.00',
        ]));

        $this->post('/api/ocr/eis', [
            'image' => $this->png(),
            'scheme' => 'eis',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.extracted.receipt_number', '2026E0003785051')
            ->assertJsonPath('data.extracted.contribution_reference', 'ECR082260127652');
    }

    public function test_it_normalizes_thousands_separators_from_ocr(): void
    {
        $this->bindOcrText(implode("\n", [
            'RESIT RASMI No. Resit : 20260004540969',
            'Tarikh Bayaran : 06/08/2026',
            'Jenis Bayaran : Caruman Bulanan(ACR082260152714-07/2026)(RM1,234.56)',
            'Kod Majikan : F9702103813F',
            'Nama Majikan : EDOCPOS SDN. BHD.',
            'FPX Transaksi ID : 2608061236580909',
            'Bank : Public Bank Berhad',
            'Jumlah Bayaran : RM 1,234.56',
        ]));

        $this->post('/api/ocr/socso', [
            'image' => $this->png(),
            'scheme' => 'socso',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.extracted.amount', 1234.56);
    }

    public function test_it_marks_missing_employer_number_for_review(): void
    {
        $this->bindOcrText(implode("\n", [
            'RESIT RASMI No. Resit : 20260004540969',
            'Tarikh Bayaran : 06/08/2026',
            'Jenis Bayaran : Caruman Bulanan(ACR082260152714-07/2026)(RM318.45)',
            'Nama Majikan : EDOCPOS SDN. BHD.',
            'FPX Transaksi ID : 2608061236580909',
            'Jumlah Bayaran : RM318.45',
        ]));

        $this->post('/api/ocr/socso', [
            'image' => $this->png(),
            'scheme' => 'socso',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.extracted.employer_number', null)
            ->assertJsonPath('meta.requires_manual_review', true);
    }

    public function test_it_marks_missing_contribution_period_for_review(): void
    {
        $this->bindOcrText(implode("\n", [
            'RESIT RASMI No. Resit : 20260004540969',
            'Tarikh Bayaran : 06/08/2026',
            'Jenis Bayaran : Caruman Bulanan(ACR082260152714)(RM318.45)',
            'Kod Majikan : F9702103813F',
            'Nama Majikan : EDOCPOS SDN. BHD.',
            'FPX Transaksi ID : 2608061236580909',
            'Jumlah Bayaran : RM318.45',
        ]));

        $this->post('/api/ocr/socso', [
            'image' => $this->png(),
            'scheme' => 'socso',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.extracted.contribution_period', null)
            ->assertJsonPath('meta.requires_manual_review', true);
    }

    public function test_it_marks_low_confidence_extractions_for_review(): void
    {
        $this->bindOcrText(implode("\n", [
            'RESIT RASMI No. Resit : 20260004540969',
            'Tarikh Bayaran : 06/08/2026',
            'Jenis Bayaran : Caruman Bulanan(ACR082260152714-07/2026)(RM318.45)',
            'Kod Majikan : F9702103813F',
            'Jumlah Bayaran : RM318.45',
        ]), 0.20);

        $this->post('/api/ocr/socso', [
            'image' => $this->png(),
            'scheme' => 'socso',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('meta.requires_manual_review', true);
    }

    public function test_it_rate_limits_statutory_endpoints(): void
    {
        config(['ocr.statutory_rate_limit_per_minute' => 1]);

        $this->post('/api/ocr/eis', [
            'image' => $this->pdfFixture('eis.pdf'),
            'scheme' => 'eis',
        ], ['Accept' => 'application/json'])->assertOk();

        $response = $this->post('/api/ocr/eis', [
            'image' => $this->pdfFixture('eis.pdf'),
            'scheme' => 'eis',
        ], ['Accept' => 'application/json'])
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'rate_limited');

        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
    }

    public function test_it_never_returns_html_for_api_errors(): void
    {
        $response = $this->post('/api/ocr/eis', [
            'scheme' => 'eis',
        ]);

        $response->assertUnprocessable();
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertStringNotContainsString('<html', $response->getContent());
        $this->assertStringNotContainsString('<!DOCTYPE', $response->getContent());
    }

    public function test_it_does_not_log_sensitive_receipt_contents(): void
    {
        Log::spy();

        $this->post('/api/ocr/eis', [
            'image' => $this->pdfFixture('eis.pdf'),
            'scheme' => 'eis',
        ], ['Accept' => 'application/json'])->assertOk();

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
            $encoded = json_encode($context) ?: '';

            return ! str_contains($encoded, '2026E0003785051')
                && ! str_contains($encoded, 'ECR082260127652')
                && ! str_contains($encoded, 'F9702103813F')
                && ! str_contains($encoded, '2608061236580909')
                && ! str_contains($encoded, 'EDOCPOS');
        })->atLeast()->once();
    }

    public function test_it_does_not_log_pcb_identifiers_or_email(): void
    {
        Log::spy();

        $this->post('/api/ocr/pcb', [
            'image' => $this->pdfFixture('pcb-confirmation-slip.pdf'),
            'scheme' => 'pcb',
        ], ['Accept' => 'application/json'])->assertOk();

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
            $encoded = json_encode($context) ?: '';

            return ! str_contains($encoded, 'yihsien86')
                && ! str_contains($encoded, 'EM2601419060')
                && ! str_contains($encoded, 'E9618231011')
                && ! str_contains($encoded, '97101269909453');
        })->atLeast()->once();
    }

    public function test_epf_and_hrdc_extract_common_fields_and_require_review(): void
    {
        $this->bindOcrText(implode("\n", [
            'No. Resit : KW-1001',
            'Tarikh Bayaran : 06/08/2026',
            'Kod Majikan : E1234567890',
            'Nama Majikan : Example Sdn Bhd',
            'Jumlah Bayaran : RM125.60',
        ]));

        foreach (['epf', 'hrdc'] as $scheme) {
            $this->post('/api/ocr/'.$scheme, [
                'image' => $this->png(),
                'scheme' => $scheme,
            ], ['Accept' => 'application/json'])
                ->assertOk()
                ->assertJsonPath('data.extracted.receipt_number', 'KW-1001')
                ->assertJsonPath('data.extracted.amount', 125.6)
                ->assertJsonPath('meta.scheme', $scheme)
                ->assertJsonPath('meta.requires_manual_review', true);
        }
    }

    public function test_it_extracts_pcb_acceptance_letter_sample(): void
    {
        $this->post('/api/ocr/pcb', [
            'image' => $this->pdfFixture('pcb-acceptance-letter.pdf'),
            'scheme' => 'pcb',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.extracted.receipt_number', '20-EM2601419060')
            ->assertJsonPath('data.extracted.payment_date', '13/08/2026')
            ->assertJsonPath('data.extracted.contribution_period', '07/2026')
            ->assertJsonPath('data.extracted.contribution_reference', '97101269909453')
            ->assertJsonPath('data.extracted.employer_number', 'E9618231011')
            ->assertJsonPath('data.extracted.amount', 1663)
            ->assertJsonPath('meta.scheme', 'pcb')
            ->assertJsonPath('meta.requires_manual_review', false)
            ->assertJsonPath('meta.source', 'embedded_text');
    }

    public function test_it_extracts_pcb_confirmation_slip_sample(): void
    {
        $this->post('/api/ocr/pcb', [
            'image' => $this->pdfFixture('pcb-confirmation-slip.pdf'),
            'scheme' => 'pcb',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.extracted.receipt_number', 'EM2601419060')
            ->assertJsonPath('data.extracted.transaction_id', '2608130845060963')
            ->assertJsonPath('data.extracted.employer_number', 'E9618231011')
            ->assertJsonPath('data.extracted.contribution_period', '07/2026')
            ->assertJsonPath('data.extracted.contribution_reference', '97101269909453')
            ->assertJsonPath('data.extracted.amount', 1663)
            ->assertJsonPath('meta.requires_manual_review', false);
    }

    public function test_it_extracts_pcb_official_receipt_sample(): void
    {
        $this->post('/api/ocr/pcb', [
            'image' => $this->pdfFixture('pcb-official-receipt.pdf'),
            'scheme' => 'pcb',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.extracted.receipt_number', '20-30039735355')
            ->assertJsonPath('data.extracted.employer_number', 'E9623455102')
            ->assertJsonPath('data.extracted.transaction_id', 'EM2601396751')
            ->assertJsonPath('data.extracted.contribution_period', '07/2026')
            ->assertJsonPath('data.extracted.contribution_reference', '1626023469013007')
            ->assertJsonPath('data.extracted.employer_name', 'SUREBEST SEAFOOD ENTERPRISE PL T')
            ->assertJsonPath('data.extracted.amount', 1120.4)
            ->assertJsonPath('data.extracted.bank', null)
            ->assertJsonPath('meta.requires_manual_review', false);
    }

    public function test_it_rejects_a_pcb_receipt_on_the_socso_endpoint(): void
    {
        $this->post('/api/ocr/socso', [
            'image' => $this->pdfFixture('pcb-acceptance-letter.pdf'),
            'scheme' => 'socso',
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'receipt_scheme_mismatch')
            ->assertJsonPath('data.detected_scheme', 'pcb')
            ->assertJsonPath('data.expected_scheme', 'socso');
    }

    public function test_it_rejects_a_socso_receipt_on_the_pcb_endpoint(): void
    {
        $this->post('/api/ocr/pcb', [
            'image' => $this->pdfFixture('socso.pdf'),
            'scheme' => 'pcb',
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'receipt_scheme_mismatch')
            ->assertJsonPath('data.detected_scheme', 'socso')
            ->assertJsonPath('data.expected_scheme', 'pcb');
    }

    public function test_it_keeps_jumlah_bayaran_when_socso_includes_skbbk(): void
    {
        $this->bindOcrText(implode("\n", [
            'RESIT RASMI No. Resit : 20260004540969',
            'Tarikh Bayaran : 06/08/2026',
            'Jenis Bayaran : 1.Caruman Bulanan(ACR082260152714-07/2026)(RM300.00) 2.SKBBK / LINDUNG 24 Jam (RM18.45)',
            'Kod Majikan : F9702103813F',
            'Nama Majikan : EDOCPOS SDN. BHD.',
            'FPX Transaksi ID : 2608061236580909',
            'Jumlah Bayaran : RM318.45',
        ]));

        $this->post('/api/ocr/socso', [
            'image' => $this->png(),
            'scheme' => 'socso',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.extracted.amount', 318.45)
            ->assertJsonPath('data.extracted.contribution_reference', 'ACR082260152714');
    }

    public function test_temporary_upload_is_deleted_after_processing(): void
    {
        $directory = storage_path('app/private/ocr-tmp');
        if (is_dir($directory)) {
            foreach (glob($directory.'/*') ?: [] as $path) {
                if (! str_ends_with($path, '.gitignore')) {
                    @unlink($path);
                }
            }
        }

        $this->post('/api/ocr/eis', [
            'image' => $this->pdfFixture('eis.pdf'),
            'scheme' => 'eis',
        ], ['Accept' => 'application/json'])->assertOk();

        $tmpFiles = glob(storage_path('app/private/ocr-tmp/*')) ?: [];
        $tmpFiles = array_values(array_filter($tmpFiles, static fn (string $path): bool => ! str_ends_with($path, '.gitignore')));

        $this->assertSame([], $tmpFiles);
    }
}
