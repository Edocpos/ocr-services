<?php

namespace Tests\Unit\StatutoryOcr;

use App\Services\StatutoryOcr\LabeledReceiptReader;
use App\Services\StatutoryOcr\PdfEmbeddedTextExtractor;
use App\Services\StatutoryOcr\ReceiptValueNormalizer;
use App\Services\StatutoryOcr\SchemeDetector;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('pcbFixtures')]
    public function test_it_extracts_pcb_sample_fields(string $fixture, array $expected): void
    {
        $text = (new PdfEmbeddedTextExtractor)->extract(
            (string) file_get_contents(base_path('tests/Fixtures/receipts/'.$fixture))
        );
        $this->assertNotNull($text);
        $this->assertSame('pcb', (new SchemeDetector)->detect($text));

        $fields = (new LabeledReceiptReader(new ReceiptValueNormalizer))->read($text);

        foreach ($expected as $key => $value) {
            $this->assertSame($value, $fields[$key], $fixture.' '.$key);
        }
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function pcbFixtures(): array
    {
        return [
            'acceptance letter' => ['pcb-acceptance-letter.pdf', [
                'receipt_number' => '20-EM2601419060',
                'payment_date' => '13/08/2026',
                'contribution_period' => '07/2026',
                'contribution_reference' => '97101269909453',
                'employer_number' => 'E9618231011',
                'amount' => 1663.0,
                'payment_description' => 'e-PCB',
            ]],
            'confirmation slip' => ['pcb-confirmation-slip.pdf', [
                'receipt_number' => 'EM2601419060',
                'payment_date' => '13/08/2026',
                'contribution_period' => '07/2026',
                'contribution_reference' => '97101269909453',
                'employer_number' => 'E9618231011',
                'employer_name' => 'TECHFUSION ENGINEERING SDN. BHD.',
                'transaction_id' => '2608130845060963',
                'amount' => 1663.0,
                'payment_description' => '092 - POTONGAN CUKAI BULANAN (PCB)',
            ]],
            'official receipt' => ['pcb-official-receipt.pdf', [
                'receipt_number' => '20-30039735355',
                'payment_date' => '11/08/2026',
                'contribution_period' => '07/2026',
                'contribution_reference' => '1626023469013007',
                'employer_number' => 'E9623455102',
                'employer_name' => 'SUREBEST SEAFOOD ENTERPRISE PL T',
                'transaction_id' => 'EM2601396751',
                'bank' => null,
                'amount' => 1120.4,
            ]],
        ];
    }
}
