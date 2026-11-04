<?php

namespace App\Contracts\Ocr;

interface StructuredAccountingClient
{
    /** @return array<string, mixed> */
    public function recommend(array $request): array;
}
