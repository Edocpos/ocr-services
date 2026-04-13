<?php

namespace App\Services\IcOcr;

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
        ];

        if (! $meta['enabled']) {
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
