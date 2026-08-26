<?php

namespace App\Services\StatutoryOcr\Parsers;

class EpfReceiptParser extends UnsupportedSchemeParser
{
    public function scheme(): string
    {
        return 'epf';
    }
}
