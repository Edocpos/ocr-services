<?php

namespace App\Services\StatutoryOcr\Parsers;

class SocsoReceiptParser extends AbstractSchemeParser
{
    public function scheme(): string
    {
        return 'socso';
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

        if (preg_match('/\b(?:SKBBK|LINDUNG\s*24)/i', $text) === 1) {
            $warnings[] = 'SOCSO amount may include SKBBK or Lindung 24 Jam contributions.';
        }

        $missingCore = $this->missingCoreWarnings($fields, [
            'receipt_number',
            'amount',
            'contribution_period',
            'employer_number',
            'contribution_reference',
        ]);

        return $this->result($fields, $warnings, $missingCore !== [], $source);
    }
}
