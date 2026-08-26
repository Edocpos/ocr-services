<?php

namespace App\Services\StatutoryOcr;

use App\Services\StatutoryOcr\Contracts\SchemeParser;
use App\Services\StatutoryOcr\Parsers\EisReceiptParser;
use App\Services\StatutoryOcr\Parsers\EpfReceiptParser;
use App\Services\StatutoryOcr\Parsers\HrdcReceiptParser;
use App\Services\StatutoryOcr\Parsers\PcbReceiptParser;
use App\Services\StatutoryOcr\Parsers\SocsoReceiptParser;
use InvalidArgumentException;

class SchemeParserFactory
{
    public function __construct(
        private readonly EpfReceiptParser $epf,
        private readonly SocsoReceiptParser $socso,
        private readonly EisReceiptParser $eis,
        private readonly PcbReceiptParser $pcb,
        private readonly HrdcReceiptParser $hrdc,
    ) {}

    public function make(string $scheme): SchemeParser
    {
        return match ($scheme) {
            'epf' => $this->epf,
            'socso' => $this->socso,
            'eis' => $this->eis,
            'pcb' => $this->pcb,
            'hrdc' => $this->hrdc,
            default => throw new InvalidArgumentException('Unsupported statutory receipt scheme.'),
        };
    }
}
