<?php

namespace App\Exceptions;

use RuntimeException;

class AccountingOcrException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message, $status);
    }

    public static function multipleTransactions(): self
    {
        return new self(
            'multiple_transactions_detected',
            'The document contains multiple unrelated transactions. Upload one logical transaction per request.',
            422,
        );
    }

    public static function unreadable(): self
    {
        return new self('accounting_document_unreadable', 'The accounting document could not be read reliably.', 422);
    }

    public static function unavailable(): self
    {
        return new self('ocr_unavailable', 'The accounting OCR service is temporarily unavailable.', 503);
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['message' => $this->getMessage(), 'error_code' => $this->errorCode];
    }
}
