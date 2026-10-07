<?php

namespace App\Services\AccountingOcr;

class MsicRegistry
{
    private ?array $catalogue = null;

    public function catalogue(): array
    {
        return $this->catalogue ??= json_decode(file_get_contents(dirname(__DIR__, 3).'/resources/accounting/msic.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function find(string $code): ?array
    {
        $entry = $this->catalogue()['codes'][$code] ?? null;

        return $entry === null ? null : ['code' => $code] + $entry;
    }
}
