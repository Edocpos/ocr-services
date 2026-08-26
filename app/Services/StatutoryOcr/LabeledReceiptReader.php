<?php

namespace App\Services\StatutoryOcr;

class LabeledReceiptReader
{
    public function __construct(
        private readonly ReceiptValueNormalizer $normalizer,
    ) {}

    /**
     * @return array{
     *     receipt_number:?string,
     *     payment_date:?string,
     *     contribution_period:?string,
     *     contribution_reference:?string,
     *     employer_number:?string,
     *     employer_name:?string,
     *     transaction_id:?string,
     *     bank:?string,
     *     amount:?float,
     *     payment_description:?string
     * }
     */
    public function read(string $text): array
    {
        $collapsed = $this->collapse($text);
        $paymentDescription = $this->capture(
            $collapsed,
            '(?:Jenis\s*Bayaran|Payment\s*Type)',
            'Kod\s*Majikan|Nama\s*Majikan|Employer|Kaedah|FPX|Jumlah'
        );
        $contribution = $this->extractContribution($paymentDescription ?? $collapsed);

        return [
            'receipt_number' => $this->normalizer->normalizeIdentifier(
                $this->captureToken($collapsed, '(?:No\.?\s*Resit|Receipt\s*(?:No|Number))', '[A-Z0-9]+(?:-[A-Z0-9]+)*')
            ),
            'payment_date' => $this->normalizer->normalizeDate(
                $this->captureToken($collapsed, '(?:Tarikh\s*Bayaran|Payment\s*Date)', '[0-9]{1,4}[\/\-.][0-9]{1,2}[\/\-.][0-9]{2,4}')
            ),
            'contribution_period' => $this->normalizer->normalizePeriod($contribution['period'] ?? $paymentDescription ?? $collapsed),
            'contribution_reference' => $this->normalizer->normalizeIdentifier($contribution['reference'] ?? null),
            'employer_number' => $this->normalizer->normalizeIdentifier(
                $this->captureToken($collapsed, '(?:Kod\s*Majikan|Employer\s*(?:No|Number|Code))', '[A-Z0-9]+')
            ),
            'employer_name' => $this->normalizer->normalizeText(
                $this->capture($collapsed, '(?:Nama\s*Majikan|Employer\s*Name)', 'Kaedah|FPX|Bank|Jumlah|Payment\s*Method')
            ),
            'transaction_id' => $this->normalizer->normalizeIdentifier(
                $this->captureToken($collapsed, '(?:FPX\s*Transaksi\s*ID|Transaction\s*ID)', '[A-Z0-9]+')
            ),
            'bank' => $this->normalizer->normalizeText(
                $this->capture($collapsed, '(?:Bank)', 'Jumlah|Catatan|Amount|Remarks')
            ),
            'amount' => $this->extractAmount($collapsed, $paymentDescription),
            'payment_description' => $this->normalizer->normalizeText($paymentDescription),
        ];
    }

    /**
     * @return array{reference:?string, period:?string}
     */
    public function extractContribution(?string $text): array
    {
        if ($text === null || $text === '') {
            return ['reference' => null, 'period' => null];
        }

        if (preg_match('/\b((?:ACR|ECR)[A-Z0-9]+)(?:-(\d{2}\/\d{4}))?/i', $text, $matches) === 1) {
            return [
                'reference' => strtoupper($matches[1]),
                'period' => $matches[2] ?? null,
            ];
        }

        return ['reference' => null, 'period' => null];
    }

    private function extractAmount(string $text, ?string $paymentDescription): ?float
    {
        $jumlah = $this->capture($text, '(?:Jumlah\s*Bayaran|Total\s*(?:Payment|Amount)|Amount)', 'Catatan|Remarks');
        $fromTotal = $this->normalizer->normalizeAmount($this->firstRmAmount($jumlah ?? ''));

        if ($fromTotal !== null) {
            return $fromTotal;
        }

        return $this->normalizer->normalizeAmount($this->firstRmAmount($paymentDescription ?? $text));
    }

    private function firstRmAmount(string $text): ?string
    {
        if (preg_match('/RM\s*[\d,]+(?:\.\d{1,2})?/i', $text, $matches) === 1) {
            return $matches[0];
        }

        if (preg_match('/\b[\d]{1,3}(?:,\d{3})+(?:\.\d{1,2})?\b/', $text, $matches) === 1) {
            return $matches[0];
        }

        return null;
    }

    private function captureToken(string $text, string $label, string $token): ?string
    {
        if (preg_match('/'.$label.'\s*:?\s*('.$token.')/iu', $text, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function capture(string $text, string $label, string $stop): ?string
    {
        $pattern = '/'.$label.'\s*:?\s*(.+?)(?=\s*:?\s*(?:'.$stop.')|$)/iu';

        if (preg_match($pattern, $text, $matches) !== 1) {
            return null;
        }

        $value = trim($matches[1], " \t\n\r\0\x0B:-");

        return $value !== '' ? $value : null;
    }

    private function collapse(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
