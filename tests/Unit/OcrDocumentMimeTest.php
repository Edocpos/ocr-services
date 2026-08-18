<?php

namespace Tests\Unit;

use App\Services\IcOcr\IcImagePreprocessor;
use App\Support\OcrDocumentMime;
use Tests\TestCase;

class OcrDocumentMimeTest extends TestCase
{
    public function test_it_detects_pdf_magic_bytes(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";

        $this->assertSame('application/pdf', OcrDocumentMime::detect($pdf));
        $this->assertTrue(OcrDocumentMime::isPdf($pdf));
    }

    public function test_it_detects_jpeg_and_png(): void
    {
        $this->assertSame('image/jpeg', OcrDocumentMime::detect("\xFF\xD8\xFF\xE0"));
        $this->assertSame('image/png', OcrDocumentMime::detect("\x89PNG\r\n\x1a\n"));
    }

    public function test_preprocessor_passes_pdf_through_when_rasterization_is_unavailable(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";
        $prepared = (new IcImagePreprocessor())->prepare($pdf);

        $this->assertSame($pdf, $prepared['content']);
        $this->assertSame('application/pdf', $prepared['meta']['mime']);
        $this->assertContains($prepared['meta']['reason'], ['pdf_passthrough', 'pdf_rasterized']);
    }
}
