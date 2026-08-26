<?php

namespace App\Services\StatutoryOcr\Parsers;

class PcbReceiptParser extends AbstractSchemeParser
{
    public function scheme(): string
    {
        return 'pcb';
    }

    public function parse(array $fields, string $text, string $source): array
    {
        $warnings = $this->missingCoreWarnings($fields, [
            'receipt_number',
            'amount',
            'contribution_period',
            'employer_number',
        ]);

        if (! $this->looksLikePcb($text)) {
            $warnings[] = 'PCB-specific markers were not clearly identified on this receipt.';
        }

        return $this->result($fields, $warnings, $warnings !== [], $source);
    }

    private function looksLikePcb(string $text): bool
    {
        return preg_match(
            '/\be-PCB\b|POTONGAN\s+CUKAI\s+BULANAN|PCB\s*Account\s*No|\bCP\s*502R\b|\bCP\s*6A\b|\b092\s*-?\s*POTONGAN\s+CUKAI/i',
            $text
        ) === 1;
    }
}
