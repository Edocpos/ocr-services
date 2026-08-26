<?php

namespace App\Exceptions;

use RuntimeException;

class StatutoryReceiptException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        public readonly array $data = [],
        public readonly array $errors = [],
    ) {
        parent::__construct($message, $status);
    }

    public static function schemeMismatch(string $detected, string $expected): self
    {
        $detectedLabel = strtoupper($detected);
        $expectedLabel = strtoupper($expected);

        $detectedArticle = in_array($detected, ['eis', 'epf'], true) ? 'an' : 'a';

        return new self(
            'receipt_scheme_mismatch',
            "This appears to be {$detectedArticle} {$detectedLabel} receipt, not a {$expectedLabel} receipt.",
            422,
            [
                'detected_scheme' => $detected,
                'expected_scheme' => $expected,
            ],
        );
    }

    public static function unreadable(): self
    {
        return new self(
            'receipt_requires_manual_review',
            'The receipt could not be read reliably.',
            422,
        );
    }

    public static function unavailable(): self
    {
        return new self(
            'ocr_unavailable',
            'The OCR service is temporarily unavailable.',
            503,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'message' => $this->getMessage(),
            'error_code' => $this->errorCode,
        ];

        if ($this->data !== []) {
            $payload['data'] = $this->data;
        }

        if ($this->errors !== []) {
            $payload['errors'] = $this->errors;
        }

        return $payload;
    }
}
