<?php

namespace App\Services\StatutoryOcr;

class SchemeDetector
{
    public function detect(string $text): ?string
    {
        $hasEcr = preg_match('/\bECR[A-Z0-9]+/i', $text) === 1;
        $hasAcr = preg_match('/\bACR[A-Z0-9]+/i', $text) === 1;

        if ($hasEcr && ! $hasAcr) {
            return 'eis';
        }

        if ($hasAcr && ! $hasEcr) {
            return 'socso';
        }

        return null;
    }
}
