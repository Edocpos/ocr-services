<?php

namespace App\Contracts\Ocr;

interface AccountingOcrClient
{
    /**
     * @param  list<array<string, mixed>>  $accounts
     * @return array<string, mixed>
     */
    public function extract(string $documentContent, string $mimeType, array $accounts): array;
}
