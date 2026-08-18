<?php

namespace App\Services\IcOcr;

use App\Support\OcrDocumentMime;

class IcImagePreprocessor
{
    /**
     * @return array{content:string,meta:array<string,mixed>}
     */
    public function prepare(string $content): array
    {
        $meta = [
            'enabled' => (bool) config('ocr.preprocess_enable', true),
            'transformed' => false,
            'reason' => 'none',
            'original_bytes' => strlen($content),
            'final_bytes' => strlen($content),
            'mime' => OcrDocumentMime::detect($content),
        ];

        if (! $meta['enabled']) {
            return ['content' => $content, 'meta' => $meta];
        }

        if (OcrDocumentMime::isPdf($content)) {
            $rasterized = $this->rasterizePdf($content);
            if ($rasterized !== null) {
                $meta['transformed'] = true;
                $meta['reason'] = 'pdf_rasterized';
                $meta['mime'] = 'image/jpeg';
                $meta['final_bytes'] = strlen($rasterized);

                return ['content' => $rasterized, 'meta' => $meta];
            }

            $meta['reason'] = 'pdf_passthrough';

            return ['content' => $content, 'meta' => $meta];
        }

        $imageInfo = @getimagesizefromstring($content);
        if (! is_array($imageInfo)) {
            $meta['reason'] = 'non_image_or_unknown_format';

            return ['content' => $content, 'meta' => $meta];
        }

        $width = (int) ($imageInfo[0] ?? 0);
        $height = (int) ($imageInfo[1] ?? 0);
        $mime = (string) ($imageInfo['mime'] ?? '');

        $meta['mime'] = $mime;
        $meta['original_width'] = $width;
        $meta['original_height'] = $height;

        $maxDimension = (int) config('ocr.preprocess_max_dimension', 2200);
        $maxBytesForDirect = (int) config('ocr.preprocess_max_bytes_for_direct', 3 * 1024 * 1024);

        $tooLargeDimension = max($width, $height) > $maxDimension;
        $tooLargeBytes = strlen($content) > $maxBytesForDirect;

        if (! $tooLargeDimension && ! $tooLargeBytes) {
            return ['content' => $content, 'meta' => $meta];
        }

        if (! function_exists('imagecreatefromstring') || ! function_exists('imagecopyresampled')) {
            $meta['reason'] = 'gd_unavailable';

            return ['content' => $content, 'meta' => $meta];
        }

        $image = @imagecreatefromstring($content);
        if ($image === false) {
            $meta['reason'] = 'image_decode_failed';

            return ['content' => $content, 'meta' => $meta];
        }

        try {
            $scale = $tooLargeDimension ? ($maxDimension / max($width, $height)) : 1.0;
            $targetWidth = max(1, (int) round($width * min(1.0, $scale)));
            $targetHeight = max(1, (int) round($height * min(1.0, $scale)));

            $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
            if ($canvas === false) {
                $meta['reason'] = 'canvas_create_failed';

                return ['content' => $content, 'meta' => $meta];
            }

            try {
                if ($mime === 'image/png' || $mime === 'image/webp') {
                    imagealphablending($canvas, false);
                    imagesavealpha($canvas, true);
                    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
                    imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $transparent);
                }

                imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

                $encoded = $this->encodeImage($canvas, $mime);
                if ($encoded === null) {
                    $meta['reason'] = 'image_encode_failed';

                    return ['content' => $content, 'meta' => $meta];
                }

                $meta['final_bytes'] = strlen($encoded);
                $meta['final_width'] = $targetWidth;
                $meta['final_height'] = $targetHeight;

                if (strlen($encoded) < strlen($content)) {
                    $meta['transformed'] = true;
                    $meta['reason'] = 'downscaled';

                    return ['content' => $encoded, 'meta' => $meta];
                }

                $meta['reason'] = 'encoded_not_smaller';

                return ['content' => $content, 'meta' => $meta];
            } finally {
                imagedestroy($canvas);
            }
        } finally {
            imagedestroy($image);
        }
    }

    private function rasterizePdf(string $content): ?string
    {
        if (! class_exists(\Imagick::class) || ! $this->ghostscriptAvailable()) {
            return null;
        }

        try {
            $imagick = new \Imagick();
            $imagick->setResolution(150, 150);
            $imagick->readImageBlob($content);

            $pageCount = min(3, max(1, $imagick->getNumberImages()));
            $pages = new \Imagick();

            for ($index = 0; $index < $pageCount; $index++) {
                $imagick->setIteratorIndex($index);
                $page = $imagick->getImage();
                $page->setImageBackgroundColor('white');
                $page->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
                $page->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
                $pages->addImage($page);
                $page->clear();
            }

            $combined = $pages->appendImages(true);
            $combined->setImageFormat('jpeg');
            $combined->setImageCompressionQuality((int) config('ocr.preprocess_jpeg_quality', 80));
            $jpeg = $combined->getImageBlob();

            $combined->clear();
            $pages->clear();
            $imagick->clear();

            return is_string($jpeg) && $jpeg !== '' ? $jpeg : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function ghostscriptAvailable(): bool
    {
        $binary = trim((string) @shell_exec('command -v gs 2>/dev/null'));

        return $binary !== '';
    }

    private function encodeImage(\GdImage $image, string $mime): ?string
    {
        ob_start();

        $ok = match ($mime) {
            'image/png' => imagepng($image, null, (int) config('ocr.preprocess_png_compression', 6)),
            'image/webp' => function_exists('imagewebp')
                ? imagewebp($image, null, (int) config('ocr.preprocess_webp_quality', 80))
                : imagejpeg($image, null, (int) config('ocr.preprocess_jpeg_quality', 80)),
            default => imagejpeg($image, null, (int) config('ocr.preprocess_jpeg_quality', 80)),
        };

        $data = ob_get_clean();

        if (! $ok || ! is_string($data) || $data === '') {
            return null;
        }

        return $data;
    }
}
