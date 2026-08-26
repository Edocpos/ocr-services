<?php

namespace App\Services\StatutoryOcr\Contracts;

interface SchemeParser
{
    public function scheme(): string;

    /**
     * @param  array<string, mixed>  $fields
     * @return array{
     *     extracted: array<string, mixed>,
     *     warnings: list<string>,
     *     requires_manual_review: bool,
     *     field_confidence: array<string, float>
     * }
     */
    public function parse(array $fields, string $text, string $source): array;
}
