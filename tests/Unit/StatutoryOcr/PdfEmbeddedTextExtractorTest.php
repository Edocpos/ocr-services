<?php

namespace Tests\Unit\StatutoryOcr;

use App\Services\StatutoryOcr\LabeledReceiptReader;
use App\Services\StatutoryOcr\PdfEmbeddedTextExtractor;
use App\Services\StatutoryOcr\ReceiptValueNormalizer;
use App\Services\StatutoryOcr\SchemeDetector;
use Tests\TestCase;

class PdfEmbeddedTextExtractorTest extends TestCase
{
    public function test_it_extracts_embedded_eis_receipt_text(): void
    {
        $text = (new PdfEmbeddedTextExtractor)->extract(
            (string) file_get_contents(base_path('tests/Fixtures/receipts/eis.pdf'))
        );

        $this->assertNotNull($text);
        $this->assertStringContainsString('2026E0003785051', $text);
        $this->assertStringContainsString('ECR082260127652', $text);
        $this->assertStringContainsString('F9702103813F', $text);
        $this->assertStringContainsString('RM50.', $text);
        $this->assertStringNotContainsString('ACR', $text);
    }

    public function test_it_extracts_embedded_socso_receipt_text(): void
    {
        $text = (new PdfEmbeddedTextExtractor)->extract(
            (string) file_get_contents(base_path('tests/Fixtures/receipts/socso.pdf'))
        );

        $this->assertNotNull($text);
        $this->assertStringContainsString('20260004540969', $text);
        $this->assertStringContainsString('ACR082260152714', $text);
        $this->assertStringContainsString('RM318.', $text);
        $this->assertStringNotContainsString('ECR', $text);
    }

    public function test_labeled_reader_maps_perkeso_fields(): void
    {
        $text = (new PdfEmbeddedTextExtractor)->extract(
            (string) file_get_contents(base_path('tests/Fixtures/receipts/socso.pdf'))
        );
        $fields = (new LabeledReceiptReader(new ReceiptValueNormalizer))->read((string) $text);

        $this->assertSame('20260004540969', $fields['receipt_number']);
        $this->assertSame('06/08/2026', $fields['payment_date']);
        $this->assertSame('07/2026', $fields['contribution_period']);
        $this->assertSame('ACR082260152714', $fields['contribution_reference']);
        $this->assertSame('F9702103813F', $fields['employer_number']);
        $this->assertSame('EDOCPOS SDN . BHD.', $fields['employer_name']);
        $this->assertSame('Public Bank Berhad', $fields['bank']);
        $this->assertSame('2608061236580909', $fields['transaction_id']);
        $this->assertSame(318.45, $fields['amount']);
        $this->assertSame('socso', (new SchemeDetector)->detect((string) $text));
    }
}
