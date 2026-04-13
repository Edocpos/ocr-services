<?php

namespace App\Contracts\Ocr;

interface OcrClient
{
    /**
     * @return array{
     *   full_text:string,
     *   lines:array<int,string>,
     *   overall_confidence:?float,
     *   pre_extracted?:array{ic_number:?string,name:?string,address:?string},
     *   usage?:array{prompt_tokens:int,completion_tokens:int,total_tokens:int}
     * }
     */
    public function detectText(string $imageContent): array;
}
