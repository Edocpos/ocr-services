<?php

namespace App\Support;

class OcrDocumentMime
{
    public static function detect(string $content): string
    {
        $header = substr($content, 0, 12);

        if (str_starts_with($content, '%PDF')) {
            return 'application/pdf';
        }

        if (str_starts_with($header, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }

        if (str_starts_with($header, "\x89PNG")) {
            return 'image/png';
        }

        if (str_starts_with($header, 'RIFF') && substr($header, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return 'image/jpeg';
    }

    public static function isPdf(string $content): bool
    {
        return self::detect($content) === 'application/pdf';
    }
}
