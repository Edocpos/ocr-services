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

        if ($this->isPcb($text)) {
            return 'pcb';
        }

        return null;
    }

    private function isPcb(string $text): bool
    {
        return preg_match(
            '/\be-PCB\b|POTONGAN\s+CUKAI\s+BULANAN|PCB\s*Account\s*No|\bCP\s*502R\b|\bCP\s*6A\b|\b092\s*-?\s*POTONGAN\s+CUKAI/i',
            $text
        ) === 1;
    }
}
