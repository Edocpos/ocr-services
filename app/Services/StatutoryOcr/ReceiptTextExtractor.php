<?php

namespace App\Services\StatutoryOcr;

use App\Contracts\Ocr\OcrClient;
use App\Exceptions\StatutoryReceiptException;
use App\Services\IcOcr\IcImagePreprocessor;
use App\Support\OcrDocumentMime;
use Throwable;

class ReceiptTextExtractor
{
    public function __construct(
        private readonly PdfEmbeddedTextExtractor $pdfExtractor,
        private readonly IcImagePreprocessor $imagePreprocessor,
        private readonly OcrClient $ocrClient,
    ) {}

    /**
     * @return array{text:string, source:string, overall_confidence:?float}
     */
    public function extract(string $content): array
    {
        if (OcrDocumentMime::isPdf($content)) {
            $embedded = $this->pdfExtractor->extract($content);

            if ($this->pdfExtractor->isReliable($embedded)) {
                return [
                    'text' => (string) $embedded,
                    'source' => 'embedded_text',
                    'overall_confidence' => 0.99,
                ];
            }
        }

        return $this->ocrWithRotation($content);
    }

    /**
     * @return array{text:string, source:string, overall_confidence:?float}
     */
    private function ocrWithRotation(string $content): array
    {
        $best = $this->ocrOnce($content);

        if ($this->isUsable($best['text'])) {
            return $best;
        }

        $image = $this->prepareImage($content);
        if ($image === null) {
            return $best;
        }

        foreach ([90, 180, 270] as $degrees) {
            $rotated = $this->rotateJpeg($image, $degrees);
            if ($rotated === null) {
                continue;
            }

            $attempt = $this->ocrOnce($rotated);
            if ($this->isUsable($attempt['text']) && mb_strlen($attempt['text']) > mb_strlen($best['text'])) {
                $best = $attempt;
            }

            if ($this->isUsable($best['text']) && mb_strlen($best['text']) >= 80) {
                break;
            }
        }

        return $best;
    }

    /**
     * @return array{text:string, source:string, overall_confidence:?float}
     */
    private function ocrOnce(string $content): array
    {
        try {
            $prepared = $this->imagePreprocessor->prepare($content);
            $payload = $this->ocrClient->detectText($prepared['content']);
        } catch (Throwable $exception) {
            throw StatutoryReceiptException::unavailable();
        }

        $text = trim((string) ($payload['full_text'] ?? ''));
        if ($text === '' && isset($payload['lines']) && is_array($payload['lines'])) {
            $text = trim(implode("\n", array_map(strval(...), $payload['lines'])));
        }

        $overall = isset($payload['overall_confidence']) && is_numeric($payload['overall_confidence'])
            ? (float) $payload['overall_confidence']
            : null;

        return [
            'text' => $text,
            'source' => 'ocr',
            'overall_confidence' => $overall,
        ];
    }

    private function prepareImage(string $content): ?string
    {
        if (OcrDocumentMime::isPdf($content)) {
            $prepared = $this->imagePreprocessor->prepare($content);

            return OcrDocumentMime::identify($prepared['content']) === 'image/jpeg'
                ? $prepared['content']
                : null;
        }

        return $content;
    }

    private function rotateJpeg(string $content, int $degrees): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagerotate')) {
            return null;
        }

        $image = @imagecreatefromstring($content);
        if ($image === false) {
            return null;
        }

        try {
            $rotated = imagerotate($image, $degrees, 0);
            if ($rotated === false) {
                return null;
            }

            ob_start();
            $ok = imagejpeg($rotated, null, (int) config('ocr.preprocess_jpeg_quality', 80));
            $data = ob_get_clean();
            imagedestroy($rotated);

            return $ok && is_string($data) && $data !== '' ? $data : null;
        } finally {
            imagedestroy($image);
        }
    }

    private function isUsable(string $text): bool
    {
        return mb_strlen(trim($text)) >= 40;
    }
}
