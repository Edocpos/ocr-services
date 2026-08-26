<?php

namespace App\Services\StatutoryOcr\Parsers;

class HrdcReceiptParser extends UnsupportedSchemeParser
{
    public function scheme(): string
    {
        return 'hrdc';
    }
}
