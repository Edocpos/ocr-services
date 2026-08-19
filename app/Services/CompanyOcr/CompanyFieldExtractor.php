<?php

namespace App\Services\CompanyOcr;

class CompanyFieldExtractor
{
    /**
     * @param  array{full_text?:string,lines?:array<int,string>,pre_extracted?:array<string,mixed>}  $ocrPayload
     * @return array<string,mixed>
     */
    public function extract(array $ocrPayload): array
    {
        if (isset($ocrPayload['pre_extracted']) && is_array($ocrPayload['pre_extracted'])) {
            return $this->fromPreExtracted($ocrPayload['pre_extracted']);
        }

        $lines = array_values(array_filter(
            array_map(static fn (mixed $line): string => trim((string) $line), $ocrPayload['lines'] ?? []),
            static fn (string $line): bool => $line !== ''
        ));
        $fullText = trim((string) ($ocrPayload['full_text'] ?? implode("\n", $lines)));

        $address = $this->extractAddress($fullText);
        $phone = $this->extractPhone($fullText);
        $statutory = $this->extractStatutoryNumbers($fullText);

        return [
            'company_name' => $this->extractCompanyName($fullText, $lines),
            'company_type' => $this->extractCompanyType($fullText),
            'ssm_number' => $this->extractSsmNumber($fullText),
            'local_trading_license' => $this->extractLocalTradingLicense($fullText),
            'local_trading_license_issuer' => $this->extractLocalTradingLicenseIssuer($fullText),
            'local_trading_license_expires_on' => $this->extractLocalTradingLicenseExpiry($fullText),
            'tin_number' => $this->extractTinNumber($fullText),
            'sst_number' => $this->extractSstNumber($fullText),
            'msic_codes' => $this->extractMsicCodes($fullText),
            'phone' => $phone,
            'country_code' => $phone !== null ? '+60' : null,
            'email' => $this->extractEmail($fullText),
            'address_line_1' => $address['address_line_1'],
            'address_line_2' => $address['address_line_2'],
            'address_line_3' => $address['address_line_3'],
            'postcode' => $address['postcode'],
            'city' => $address['city'],
            'state' => $address['state'],
            'country' => $address['country'],
            'lhdn_employer_no' => $statutory['lhdn_employer_no'],
            'epf_employer_no' => $statutory['epf_employer_no'],
            'socso_employer_no' => $statutory['socso_employer_no'],
            'hrdc_employer_no' => $statutory['hrdc_employer_no'],
            'zakat_employer_no' => $statutory['zakat_employer_no'],
            'jtk_employer_no' => $statutory['jtk_employer_no'],
        ];
    }

    /**
     * @param  array<string,mixed>  $preExtracted
     * @return array<string,mixed>
     */
    private function fromPreExtracted(array $preExtracted): array
    {
        $msicCodes = [];
        if (is_array($preExtracted['msic_codes'] ?? null)) {
            $msicCodes = $preExtracted['msic_codes'];
        }

        $stringOrNull = static function (mixed $value): ?string {
            if (! is_string($value) && ! is_numeric($value)) {
                return null;
            }

            $trimmed = trim((string) $value);

            return $trimmed !== '' ? $trimmed : null;
        };

        return [
            'company_name' => $stringOrNull($preExtracted['company_name'] ?? null),
            'company_type' => $stringOrNull($preExtracted['company_type'] ?? null),
            'ssm_number' => $stringOrNull($preExtracted['ssm_number'] ?? null),
            'local_trading_license' => $stringOrNull($preExtracted['local_trading_license'] ?? null),
            'local_trading_license_issuer' => $stringOrNull($preExtracted['local_trading_license_issuer'] ?? null),
            'local_trading_license_expires_on' => $stringOrNull($preExtracted['local_trading_license_expires_on'] ?? null),
            'tin_number' => $stringOrNull($preExtracted['tin_number'] ?? null),
            'sst_number' => $stringOrNull($preExtracted['sst_number'] ?? null),
            'msic_codes' => $msicCodes,
            'phone' => $stringOrNull($preExtracted['phone'] ?? null),
            'country_code' => $stringOrNull($preExtracted['country_code'] ?? null),
            'email' => $stringOrNull($preExtracted['email'] ?? null),
            'address_line_1' => $stringOrNull($preExtracted['address_line_1'] ?? null),
            'address_line_2' => $stringOrNull($preExtracted['address_line_2'] ?? null),
            'address_line_3' => $stringOrNull($preExtracted['address_line_3'] ?? null),
            'postcode' => $stringOrNull($preExtracted['postcode'] ?? null),
            'city' => $stringOrNull($preExtracted['city'] ?? null),
            'state' => $stringOrNull($preExtracted['state'] ?? null),
            'country' => $stringOrNull($preExtracted['country'] ?? null),
            'lhdn_employer_no' => $stringOrNull($preExtracted['lhdn_employer_no'] ?? null),
            'epf_employer_no' => $stringOrNull($preExtracted['epf_employer_no'] ?? null),
            'socso_employer_no' => $stringOrNull($preExtracted['socso_employer_no'] ?? null),
            'hrdc_employer_no' => $stringOrNull($preExtracted['hrdc_employer_no'] ?? null),
            'zakat_employer_no' => $stringOrNull($preExtracted['zakat_employer_no'] ?? null),
            'jtk_employer_no' => $stringOrNull($preExtracted['jtk_employer_no'] ?? null),
        ];
    }

    /**
     * @param  array<int,string>  $lines
     */
    private function extractCompanyName(string $fullText, array $lines): ?string
    {
        if (preg_match('/(?:NAMA\s*(?:SYARIKAT|SYKT)|COMPANY\s*NAME)\s*[:.]?\s*(.+)/i', $fullText, $matches) === 1) {
            $name = trim(preg_split('/\R/u', $matches[1])[0] ?? '');

            return $this->isBlacklistedName($name) ? null : $name;
        }

        foreach ($lines as $line) {
            if ($this->isBlacklistedName($line)) {
                continue;
            }

            if (preg_match('/\b(SDN\.?\s*BHD\.?|BERHAD|BHD\.?|LLP|PLT|ENTERPRISE)\b/i', $line) === 1) {
                return $line;
            }
        }

        return null;
    }

    private function extractCompanyType(string $fullText): ?string
    {
        return match (true) {
            (bool) preg_match('/\bSDN\.?\s*BHD\.?\b|\bSENDIRIAN\s+BERHAD\b/i', $fullText) => 'sdn_bhd',
            (bool) preg_match('/\bLLP\b|\bPLT\b|\bLIMITED\s+LIABILITY\s+PARTNERSHIP\b/i', $fullText) => 'llp',
            (bool) preg_match('/\bBERHAD\b|\bBHD\.?\b/i', $fullText) => 'bhd',
            (bool) preg_match('/\bPARTNERSHIP\b|\bPERKONGSIAN\b/i', $fullText) => 'partnership',
            (bool) preg_match('/\bSOLE\s+PROP|\bENTERPRISE\b|\bPERUSAHAAN\s+PERSENDIRIAN\b/i', $fullText) => 'sole_proprietor',
            default => null,
        };
    }

    private function extractSsmNumber(string $fullText): ?string
    {
        if (preg_match('/(?:NO\.?\s*(?:SYARIKAT|SYKT)|COMPANY\s*(?:NO|NUMBER)|REGISTRATION\s*(?:NO|NUMBER)|SSM(?:\s*NO)?)\s*[:.]?\s*([0-9]{5,12}\s*-?\s*[A-Z0-9]?)/i', $fullText, $matches) === 1) {
            return trim($matches[1]);
        }

        if (preg_match('/\b(20\d{10}|19\d{10})\b/', $fullText, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/\b(\d{5,7}\s*-\s*[A-Z])\b/', $fullText, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function extractLocalTradingLicense(string $fullText): ?string
    {
        if (preg_match('/(?:LOCAL\s+TRADING\s+LICEN[CS]E|TRADING\s+LICEN[CS]E|BUSINESS\s+LICEN[CS]E|LESEN\s+(?:PERNIAGAAN|PERDAGANGAN)(?:\s+TEMPATAN)?)\s*(?:NO\.?|NUMBER|NOMBOR)\s*[:.]?\s*([A-Z0-9][A-Z0-9\/\-]{2,40})/i', $fullText, $matches) === 1) {
            return strtoupper(trim($matches[1]));
        }

        return null;
    }

    private function extractLocalTradingLicenseIssuer(string $fullText): ?string
    {
        if (preg_match('/(?:ISSUING\s+(?:AUTHORITY|LOCAL\s+AUTHORITY)|PIHAK\s+BERKUASA(?:\s+TEMPATAN)?)\s*[:.]?\s*([^\r\n]+)/i', $fullText, $matches) === 1) {
            $issuer = trim($matches[1]);

            return $issuer !== '' ? $issuer : null;
        }

        if (preg_match('/\b(Dewan Bandaraya Kota Kinabalu|Dewan Bandaraya Kuching Utara|Dewan Bandaraya Kuching Selatan|Majlis Bandaraya Shah Alam|Majlis Bandaraya[^\r\n,]{0,40}|Majlis Perbandaran[^\r\n,]{0,40}|Majlis Daerah[^\r\n,]{0,40}|DBKK|MBKS|MBKU|MBS)\b/i', $fullText, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    private function extractLocalTradingLicenseExpiry(string $fullText): ?string
    {
        if (preg_match('/(?:EXPIR(?:Y|ES)|VALID\s+(?:UNTIL|TILL)|TARIKH\s+LUPUT)\s*[:.]?\s*(\d{4}-\d{2}-\d{2}|\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})/i', $fullText, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    private function extractTinNumber(string $fullText): ?string
    {
        if (preg_match('/(?:\bTIN\b|NO\.?\s*CUKAI|TAX\s*(?:ID|NO|IDENTIFICATION))\s*[:.]?\s*([A-Z]{0,3}\d{8,12})/i', $fullText, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        if (preg_match('/\b(C\d{8,12})\b/', $fullText, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        return null;
    }

    private function extractSstNumber(string $fullText): ?string
    {
        if (preg_match('/\b(W\d{2}\s*-?\s*\d{4}\s*-?\s*\d{8})\b/i', $fullText, $matches) === 1) {
            return strtoupper(preg_replace('/\s+/', '', $matches[1]) ?? $matches[1]);
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function extractMsicCodes(string $fullText): array
    {
        $codes = [];

        if (preg_match_all('/(?:MSIC(?:\s*CODE)?|KOD\s*MSIC)\s*[:.]?\s*(\d{5})/i', $fullText, $matches) > 0) {
            $codes = $matches[1];
        }

        return array_values(array_unique(array_slice($codes, 0, 3)));
    }

    private function extractEmail(string $fullText): ?string
    {
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $fullText, $matches) === 1) {
            return strtolower($matches[0]);
        }

        return null;
    }

    private function extractPhone(string $fullText): ?string
    {
        if (preg_match('/(?:TEL(?:EPHONE)?|PHONE|NO\.?\s*TEL|HP|MOBILE)\s*[:.]?\s*(\+?60|0)?[\s\-]*(1\d[\s\-]?\d{7,8}|3[\s\-]?\d{8}|[2-9]\d[\s\-]?\d{6,8})/i', $fullText, $matches) === 1) {
            return preg_replace('/[^\d+]/', '', ($matches[1] ?? '').($matches[2] ?? '')) ?: null;
        }

        if (preg_match('/(\+?60[\s\-]?\d{8,11}|0\d[\s\-]?\d{7,9})/', $fullText, $matches) === 1) {
            return preg_replace('/[^\d+]/', '', $matches[1]) ?: null;
        }

        return null;
    }

    /**
     * @return array{address_line_1:?string,address_line_2:?string,address_line_3:?string,postcode:?string,city:?string,state:?string,country:?string}
     */
    private function extractAddress(string $fullText): array
    {
        $empty = [
            'address_line_1' => null,
            'address_line_2' => null,
            'address_line_3' => null,
            'postcode' => null,
            'city' => null,
            'state' => null,
            'country' => null,
        ];

        $block = null;
        if (preg_match('/(?:ALAMAT(?:\s*BERDAFTAR)?|REGISTERED\s*ADDRESS|ADDRESS)\s*[:.]?\s*(.+)/is', $fullText, $matches) === 1) {
            $block = trim($matches[1]);
            $block = preg_split('/(?:\bTIN\b|\bSST\b|\bMSIC\b|\bTEL\b|\bEMAIL\b|\bPHONE\b|\bLHDN\b|\bPCB\b|\bEPF\b|\bKWSP\b|\bSOCSO\b|\bPERKESO\b|\bHRDC\b|\bMYCOID\b|\bZAKAT\b|\bJTK\b)/i', $block)[0] ?? $block;
        }

        if ($block === null) {
            return $empty;
        }

        $block = trim(preg_replace('/\s+/u', ' ', preg_replace('/\R+/u', ', ', $block) ?? $block) ?? $block, " \t\n\r,");
        $postcode = null;
        $city = null;
        $state = null;
        $country = 'Malaysia';

        if (preg_match('/\b(\d{5})\b/', $block, $matches) === 1) {
            $postcode = $matches[1];
        }

        if (preg_match('/\b(\d{5})\s+([^,]+?)(?:,\s*([^,]+?))?(?:,\s*(MALAYSIA))?\s*$/i', $block, $matches) === 1) {
            $city = trim($matches[2]);
            $state = isset($matches[3]) ? trim($matches[3]) : null;
            if (isset($matches[4])) {
                $country = 'Malaysia';
            }
            $block = trim((string) preg_replace('/,?\s*\d{5}\s+.+$/i', '', $block), " \t,");
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $block)), static fn (string $part): bool => $part !== ''));

        if (isset($parts[0], $parts[1]) && preg_match('/^(No|Lot|Unit|Tingkat|Level)\.?\s/i', $parts[0]) === 1) {
            $parts[0] = $parts[0].', '.$parts[1];
            array_splice($parts, 1, 1);
        }

        return [
            'address_line_1' => $parts[0] ?? null,
            'address_line_2' => $parts[1] ?? null,
            'address_line_3' => $parts[2] ?? null,
            'postcode' => $postcode,
            'city' => $city,
            'state' => $state,
            'country' => $country,
        ];
    }

    private function isBlacklistedName(string $line): bool
    {
        $upper = mb_strtoupper($line);

        $blacklist = [
            'SURUHANJAYA SYARIKAT MALAYSIA',
            'COMPANIES COMMISSION',
            'SIJIL PERBADANAN',
            'CERTIFICATE OF INCORPORATION',
            'PROFIL SYARIKAT',
            'COMPANY PROFILE',
            'MALAYSIA',
            'NAMA SYARIKAT',
            'COMPANY NAME',
        ];

        foreach ($blacklist as $item) {
            if ($upper === $item || str_starts_with($upper, $item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *   lhdn_employer_no:?string,
     *   epf_employer_no:?string,
     *   socso_employer_no:?string,
     *   hrdc_employer_no:?string,
     *   zakat_employer_no:?string,
     *   jtk_employer_no:?string
     * }
     */
    private function extractStatutoryNumbers(string $fullText): array
    {
        return [
            'lhdn_employer_no' => $this->extractLhdnEmployerNo($fullText),
            'epf_employer_no' => $this->extractLabeledValue(
                $fullText,
                '(?:EPF|KWSP)(?:\s*\/\s*(?:EPF|KWSP))?(?:\s*EMPLOYER)?(?:\s*(?:NO|NUMBER|NOMBOR))?|KUMPULAN\s+WANG\s+SIMPANAN\s+PEKERJA|NO\.?\s*(?:MAJIKAN|EMPLOYER)(?:\s*(?:NO|NUMBER|NOMBOR))?\s*(?:EPF|KWSP)',
                '\d{6,12}'
            ),
            'socso_employer_no' => $this->extractLabeledValue(
                $fullText,
                '(?:SOCSO|PERKESO)(?:\s*\/\s*(?:SOCSO|PERKESO|EIS))?(?:\s*EMPLOYER)?(?:\s*(?:NO|NUMBER|NOMBOR))?|EIS\s*EMPLOYER(?:\s*(?:NO|NUMBER|NOMBOR))?|NO\.?\s*(?:MAJIKAN|EMPLOYER)(?:\s*(?:NO|NUMBER|NOMBOR))?\s*(?:SOCSO|PERKESO)',
                '\d{8,12}'
            ),
            'hrdc_employer_no' => $this->extractLabeledValue(
                $fullText,
                '(?:HRDC|HRD\s*CORP)(?:\s*MYCOID)?|MYCOID',
                '\d{10,15}'
            ),
            'zakat_employer_no' => $this->extractZakatEmployerNo($fullText),
            'jtk_employer_no' => $this->extractLabeledValue(
                $fullText,
                'JTK(?:\s*EMPLOYER)?(?:\s*(?:NO|NUMBER|NOMBOR))?|JABATAN\s+TENAGA\s+KERJA|NO\.?\s*(?:MAJIKAN|EMPLOYER)(?:\s*(?:NO|NUMBER|NOMBOR))?\s*JTK',
                '\d{8,12}'
            ),
        ];
    }

    private function extractLhdnEmployerNo(string $fullText): ?string
    {
        $labeled = $this->extractLabeledValue(
            $fullText,
            'LHDN(?:\s*\/\s*PCB)?(?:\s*EMPLOYER)?(?:\s*(?:NO|NUMBER|NOMBOR|FILE))?|PCB(?:\s*EMPLOYER)?(?:\s*(?:NO|NUMBER|NOMBOR))?|NO\.?\s*(?:FAIL\s*)?(?:MAJIKAN|EMPLOYER)(?:\s*(?:NO|NUMBER|NOMBOR))?\s*(?:LHDN|PCB)',
            '(?:E\s*)?\d{8,12}'
        );

        if ($labeled !== null) {
            return $labeled;
        }

        if (preg_match('/\b(E\s*\d{8,12})\b/i', $fullText, $matches) === 1) {
            return strtoupper(preg_replace('/\s+/', '', $matches[1]) ?? $matches[1]);
        }

        return null;
    }

    private function extractZakatEmployerNo(string $fullText): ?string
    {
        $labeled = $this->extractLabeledValue(
            $fullText,
            '(?:ZAKAT|PPZ)(?:\s*\/\s*(?:ZAKAT|PPZ))?(?:\s*EMPLOYER)?(?:\s*(?:NO|NUMBER|NOMBOR))?|LEMBAGA\s+ZAKAT|NO\.?\s*(?:MAJIKAN|EMPLOYER)(?:\s*(?:NO|NUMBER|NOMBOR))?\s*(?:ZAKAT|PPZ)',
            '[A-Z0-9-]{4,20}'
        );

        if ($labeled !== null) {
            return $labeled;
        }

        if (preg_match('/\b(EMP\d{4,12})\b/i', $fullText, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        return null;
    }

    private function extractLabeledValue(string $fullText, string $labelPattern, string $valuePattern): ?string
    {
        if (preg_match('/(?:'.$labelPattern.')\s*[:.#]*\s*('.$valuePattern.')/iu', $fullText, $matches) === 1) {
            $value = strtoupper(preg_replace('/\s+/', '', $matches[1]) ?? $matches[1]);

            return $value !== '' ? $value : null;
        }

        return null;
    }
}
