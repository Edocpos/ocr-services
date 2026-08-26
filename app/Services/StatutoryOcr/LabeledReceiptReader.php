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
            'Kod\s*Majikan|Nama\s*Majikan|Employer|Kaedah|FPX|Jumlah|PCB\s*Account|Month|Receipt\s*No|Amount'
        );
        $contribution = $this->extractContribution($paymentDescription ?? $collapsed);

        $fields = [
            'receipt_number' => $this->normalizer->normalizeIdentifier(
                $this->captureToken($collapsed, '(?:No\.?\s*Resit|Receipt\s*(?:No|Number)|NOMBOR\s*RESIT)', '[A-Z0-9]+(?:-[A-Z0-9]+)*')
            ),
            'payment_date' => $this->normalizer->normalizeDate(
                $this->captureToken($collapsed, '(?:Tarikh\s*Bayaran|Payment\s*Date|Date\/Time of Transaction|TARIKH)', '[0-9]{1,4}[\/\-.][0-9]{1,2}[\/\-.][0-9]{2,4}')
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

        return $this->overlayPcbFields($fields, $collapsed, $text);
    }

    private function looksLikePcb(string $text): bool
    {
        return preg_match(
            '/\be-PCB\b|POTONGAN\s+CUKAI\s+BULANAN|PCB\s*Account\s*No|\bCP\s*502R\b|\bCP\s*6A\b|\b092\s*-?\s*POTONGAN\s+CUKAI/i',
            $text
        ) === 1;
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

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function overlayPcbFields(array $fields, string $collapsed, string $original): array
    {
        if (! $this->looksLikePcb($collapsed) && ! $this->looksLikePcb($original)) {
            return $fields;
        }

        $fields['receipt_number'] ??= $this->normalizer->normalizeIdentifier(
            $this->firstMatch($collapsed, [
                '/Receipt\s*No\.?\s*:?\s*([A-Z0-9-]+)/i',
                '/NOMBOR\s*RESIT\s*:?\s*([A-Z0-9-]+)/i',
                '/LHDNM\s*Transaction\s*No\.?\s*:?\s*([A-Z0-9-]+)/i',
            ])
        );

        $fields['payment_date'] ??= $this->normalizer->normalizeDate(
            $this->firstMatch($collapsed, [
                '/Date\/Time of Transaction\s*:?\s*(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4})/i',
                '/TARIKH\s*:?\s*(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4})/i',
                '/(?:^|\s)Date\s*:?\s*(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{4})/i',
            ])
        );

        $fields['contribution_period'] = $this->pcbContributionPeriod($collapsed);

        $pcbReference = $this->normalizer->normalizeIdentifier(
            $this->firstMatch($collapsed, [
                '/PCB\s*Account\s*No\.?\s*:?\s*([A-Z0-9]{8,})/i',
                '/PAYMENT INFORMATION\s+(\d{10,})/i',
                '/VA\s*Number\s*:?\s*(\d{10,})/i',
                '/NOMBOR\s*BIL\s*:?\s*([0-9][0-9 ]{10,})/i',
                '/(\d{4}\s+\d{4}\s+\d{4}\s+\d{4})\s+[\d,]+\.\d{2}/',
            ])
        );
        if ($pcbReference !== null) {
            $fields['contribution_reference'] = $pcbReference;
        }

        $employerNumber = $this->normalizer->normalizeIdentifier(
            $this->firstMatch($collapsed, [
                '/(?:Tax Identification No\.?\s*\(TIN\)|Employer\s*No\.?|NO\.?\s*PENGENALAN\s*CUKAI(?:\s*\(TIN\))?)\s*:?\s*(E\s*\d{8,})/i',
            ])
        );
        if ($employerNumber !== null || in_array($fields['employer_number'], [null, 'E'], true)) {
            $fields['employer_number'] = $employerNumber ?? $fields['employer_number'];
        }

        $fields['employer_name'] ??= $this->pcbEmployerName($collapsed, $original);
        $fields['transaction_id'] ??= $this->normalizer->normalizeIdentifier(
            $this->firstMatch($collapsed, [
                '/Transaction\s*ID\s*:?\s*([A-Z0-9-]+)/i',
                '/NO\.?\s*RUJUKAN\s*BAYARAN\s*(?:\/\s*TRANSAKSI)?\s*:?\s*([A-Z0-9-]+)/i',
                '/LHDNM\s*Transaction\s*No\.?\s*:?\s*([A-Z0-9-]+)/i',
            ])
        );

        $pcbBank = $this->firstMatch($collapsed, [
            '/\bBANK\s*:\s*([A-Z][A-Z0-9 .,&\'-]{2,80})/i',
        ]);
        $fields['bank'] = ($pcbBank !== null && preg_match('/^(?:BANK|BAYARAN|NO\.?|EMEL|BULAN|RUJUKAN)/i', $pcbBank) !== 1)
            ? $this->normalizer->normalizeText($pcbBank)
            : null;

        $fields['amount'] ??= $this->normalizer->normalizeAmount(
            $this->firstMatch($collapsed, [
                '/Payment\s*Amount\s*:?\s*(RM\s*[\d,]+\.\d{2})/i',
                '/(?:^|\s)Amount\s*:?\s*(RM\s*[\d,]+\.\d{2})/i',
                '/AMAUN\s*\(RM\)\s*:?\s*([\d,]+\.\d{2})/i',
                '/JUMLAH\s*:?\s*(?:RM\s*)?([\d,]+\.\d{2})/i',
            ])
        );

        $fields['payment_description'] ??= $this->normalizer->normalizeText(
            $this->firstMatch($collapsed, [
                '/Payment\s*Type\s*:?\s*(e-PCB|[A-Z0-9][A-Z0-9 \/-]{2,40})/i',
                '/JENIS\s*RESIT\s*:?\s*(POTONGAN\s+CUKAI(?:\s+BULANAN)?)/i',
                '/(092\s*-?\s*POTONGAN\s+CUKAI\s+BULANAN(?:\s*\(PCB\))?)/i',
            ])
        );

        return $fields;
    }

    private function pcbContributionPeriod(string $text): ?string
    {
        $direct = $this->normalizer->normalizePeriod(
            $this->firstMatch($text, ['/Month\/Year\s*:?\s*(\d{1,2}\s*\/\s*20\d{2})/i'])
        );
        if ($direct !== null) {
            return $direct;
        }

        $month = $this->firstMatch($text, [
            '/BULAN\s*\/\s*ANSURAN\s*:?\s*(\d{1,2})/i',
            '/\bMonth\b\s*:?\s*(\d{1,2})/i',
        ]);
        $year = $this->firstMatch($text, [
            '/TAHUN\s*TAKSIRAN\s*:?\s*(20\d{2})/i',
            '/Deduction\s*Year\s*:?\s*(20\d{2})/i',
        ]);

        if ($month !== null && $year !== null) {
            return $this->normalizer->normalizePeriod($month.'/'.$year);
        }

        if (preg_match('/RM\s*[\d,]+\.\d{2}\s+(0?[1-9]|1[0-2])\s+(20\d{2})/', $text, $matches) === 1) {
            return $this->normalizer->normalizePeriod($matches[1].'/'.$matches[2]);
        }

        return null;
    }

    private function pcbEmployerName(string $collapsed, string $original): ?string
    {
        $labeled = $this->normalizer->normalizeText(
            $this->firstMatch($collapsed, [
                '/(?:^|\s)Name\s*:?\s*(.+?)(?=\s+Employer\s*No|\s+Email|\s+Address|$)/i',
                '/DITERIMA\s*DARIPADA\s*:?\s*(.+?)(?=\s+ALAMAT|\s+NO\.?\s*PENGENALAN|$)/i',
            ])
        );
        if ($labeled !== null) {
            return $labeled;
        }

        if (preg_match('/\n([A-Z][A-Z0-9 .,&\'-]{4,}(?:SDN\.?\s*BHD\.?|PLT|ENTERPRISE)[^\n]*)\n/u', $original, $matches) === 1) {
            return $this->normalizer->normalizeText($matches[1]);
        }

        return null;
    }

    /**
     * @param  list<string>  $patterns
     */
    private function firstMatch(string $text, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches) === 1) {
                $value = trim($matches[1]);
                if ($value !== '' && $value !== '-') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function extractAmount(string $text, ?string $paymentDescription): ?float
    {
        $jumlah = $this->capture($text, '(?:Jumlah\s*Bayaran|Total\s*(?:Payment|Amount)|Amount)', 'Catatan|Remarks|Month|Thank');
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
        if (preg_match('#'.$label.'\s*:?\s*('.$token.')#iu', $text, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function capture(string $text, string $label, string $stop): ?string
    {
        $pattern = '#'.$label.'\s*:?\s*(.+?)(?=\s*:?\s*(?:'.$stop.')|$)#iu';

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
