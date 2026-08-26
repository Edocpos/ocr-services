<?php

namespace App\Services\StatutoryOcr\Parsers;

class PcbReceiptParser extends UnsupportedSchemeParser
{
    public function scheme(): string
    {
        return 'pcb';
    }
}
