<?php

namespace App\Services\StatutoryOcr\Parsers;

abstract class UnsupportedSchemeParser extends AbstractSchemeParser
{
    public function parse(array $fields, string $text, string $source): array
    {
        $warnings = [
            'Scheme-specific validation is not yet available for '.strtoupper($this->scheme()).' receipts.',
        ];
        $warnings = array_merge($warnings, $this->missingCoreWarnings($fields, [
            'receipt_number',
            'amount',
            'payment_date',
        ]));

        return $this->result($fields, $warnings, true, $source);
    }
}
