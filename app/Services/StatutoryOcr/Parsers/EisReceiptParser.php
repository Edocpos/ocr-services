<?php

namespace App\Services\StatutoryOcr\Parsers;

class EisReceiptParser extends AbstractSchemeParser
{
    public function scheme(): string
    {
        return 'eis';
    }

    public function parse(array $fields, string $text, string $source): array
    {
        $warnings = $this->missingCoreWarnings($fields, [
            'receipt_number',
            'amount',
            'contribution_period',
            'employer_number',
            'contribution_reference',
        ]);

        return $this->result($fields, $warnings, $warnings !== [], $source);
    }
}
