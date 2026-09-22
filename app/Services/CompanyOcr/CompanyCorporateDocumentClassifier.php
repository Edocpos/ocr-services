<?php

namespace App\Services\CompanyOcr;

class CompanyCorporateDocumentClassifier
{
    /**
     * Keys match the Arkcloudant company-document catalog.
     *
     * @var list<string>
     */
    public const TYPES = [
        'form_8',
        'form_9',
        'ma',
        'form_13',
        'form_24',
        'form_32a',
        'form_44',
        'form_49',
        'historical_annual_return',
        'section_14',
        'notice_of_registration',
        'section_17',
        'section_28',
        'section_32',
        'section_46',
        'section_51',
        'section_58',
        'section_58_236_2',
        'section_68',
        'section_78',
        'section_105',
        'section_236',
        'section_236_3',
    ];

    /**
     * More specific titles are listed first.
     *
     * @var array<string, string>
     */
    private const TEXT_MATCHERS = [
        'section_58_236_2' => '/SECTION\s*58\s*\/\s*236\s*\(\s*2\s*\)|FIRST\s+SECRETARY\s+APPOINTMENT|PELANTIKAN\s+SETIAUSAHA\s+PERTAMA/i',
        'section_236_3' => '/SECTION\s*236\s*\(\s*3\s*\)|SECRETARY\s+DECLARATION|AKUAN\s+SETIAUSAHA/i',
        'section_105' => '/SECTION\s*105\b|INSTRUMENT\s+OF\s+TRANSFER\s+OF\s+SHARES/i',
        'section_78' => '/SECTION\s*78\b|RETURN\s+(?:FOR|OF)\s+ALLOTMENT\s+OF\s+SHARES/i',
        'section_68' => '/SECTION\s*68\b|ANNUAL\s+RETURN|PENYATA\s+TAHUNAN/i',
        'section_51' => '/SECTION\s*51\b|NOTIFICATION\s+OF\s+CHANGE\s+IN\s+(?:THE\s+)?REGISTER\s+OF\s+MEMBERS/i',
        'section_58' => '/SECTION\s*58\b/i',
        'section_46' => '/SECTION\s*46\b/i',
        'section_32' => '/SECTION\s*32\b/i',
        'section_28' => '/SECTION\s*28\b/i',
        'form_32a' => '/FORM\s*32\s*A\b/i',
        'form_49' => '/FORM\s*49\b/i',
        'form_44' => '/FORM\s*44\b/i',
        'form_24' => '/FORM\s*24\b/i',
        'form_13' => '/FORM\s*13\b/i',
        'form_9' => '/FORM\s*9\b|CERTIFICATE\s+OF\s+INCORPORATION\s+OF\s+(?:A\s+)?PRIVATE\s+COMPANY/i',
        'form_8' => '/FORM\s*8\b|CERTIFICATE\s+OF\s+INCORPORATION\s+OF\s+(?:A\s+)?PUBLIC\s+COMPANY/i',
        'notice_of_registration' => '/NOTICE\s+OF\s+REGISTRATION|NOTIS\s+PENDAFTARAN/i',
        'section_14' => '/SECTION\s*14\b|APPLICATION\s+FOR\s+REGISTRATION|PERMOHONAN\s+PENDAFTARAN/i',
        'section_17' => '/SECTION\s*17\b|CERTIFICATE\s+OF\s+INCORPORATION|PERAKUAN\s+PEMERBADANAN/i',
        'ma' => '/MEMORANDUM\s+(?:AND|&)\s+ARTICLES|MEMORANDUM\s+OF\s+ASSOCIATION/i',
        'historical_annual_return' => '/HISTORICAL\s+ANNUAL\s+RETURN/i',
        'section_236' => '/SECTION\s*236\b/i',
    ];

    /**
     * @var array<string, string>
     */
    private const TYPE_ALIASES = [
        'section_58_2362' => 'section_58_236_2',
        'section_58_236_2' => 'section_58_236_2',
        'section_2363' => 'section_236_3',
        'section_236_3' => 'section_236_3',
        'form_32_a' => 'form_32a',
        'memorandum_and_articles' => 'ma',
        'memorandum_articles' => 'ma',
        'memorandum_of_association' => 'ma',
        'articles_of_association' => 'ma',
        'application_for_registration' => 'section_14',
        'notis_pendaftaran' => 'notice_of_registration',
        'certificate_of_incorporation' => 'section_17',
        'annual_return' => 'section_68',
    ];

    public function classify(?string $explicitType, string $fullText): ?string
    {
        $normalized = $this->normalizeType($explicitType);
        if ($normalized !== null) {
            return $normalized;
        }

        return $this->matchText($fullText);
    }

    /**
     * @return array{
     *     document_date: ?string,
     *     effective_date: ?string,
     *     lodgement_date: ?string,
     *     ssm_reference: ?string,
     *     annual_return_year: ?string
     * }
     */
    public function extractRegisterHints(string $fullText): array
    {
        return [
            'document_date' => $this->labelledDate($fullText, [
                'DOCUMENT DATE',
                'DATE OF DOCUMENT',
                'TARIKH DOKUMEN',
                'DATED',
            ]),
            'effective_date' => $this->labelledDate($fullText, [
                'EFFECTIVE DATE',
                'DATE OF EFFECT',
                'TARIKH KUAT KUASA',
            ]),
            'lodgement_date' => $this->labelledDate($fullText, [
                'LODGEMENT DATE',
                'DATE OF LODGEMENT',
                'LODGED ON',
                'TARIKH SERAHAN',
            ]),
            'ssm_reference' => $this->labelledToken($fullText, [
                'SSM REFERENCE',
                'REFERENCE NO',
                'NO. RUJUKAN',
                'NO RUJUKAN',
            ]),
            'annual_return_year' => $this->extractAnnualReturnYear($fullText),
        ];
    }

    public function normalizeType(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $key = strtolower(trim($value));
        $key = str_replace(['/', '(', ')', '.', '-'], ' ', $key);
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? '';
        $key = trim($key, '_');

        if ($key === '' || $key === 'null' || $key === 'none' || $key === 'other') {
            return null;
        }

        if (in_array($key, self::TYPES, true)) {
            return $key;
        }

        if (isset(self::TYPE_ALIASES[$key])) {
            return self::TYPE_ALIASES[$key];
        }

        $types = self::TYPES;
        usort($types, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        foreach ($types as $type) {
            if (str_starts_with($key, $type.'_')) {
                return $type;
            }
        }

        return null;
    }

    public function extractAnnualReturnYear(string $fullText): ?string
    {
        $patterns = [
            '/SECTION\s*68\D{0,24}((?:19|20)\d{2})/i',
            '/ANNUAL\s+RETURN\D{0,30}((?:19|20)\d{2})/i',
            '/PENYATA\s+TAHUNAN\D{0,24}((?:19|20)\d{2})/i',
            '/(?:FOR\s+THE\s+YEAR|YEAR\s+ENDED|TAHUN)\D{0,12}((?:19|20)\d{2})/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $fullText, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    private function matchText(string $fullText): ?string
    {
        if (trim($fullText) === '') {
            return null;
        }

        foreach (self::TEXT_MATCHERS as $type => $pattern) {
            if (preg_match($pattern, $fullText) === 1) {
                return $type;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $labels
     */
    private function labelledDate(string $fullText, array $labels): ?string
    {
        $labelPattern = implode('|', array_map(static fn (string $label): string => preg_quote($label, '/'), $labels));
        $pattern = '/(?:'.$labelPattern.')\s*[:.]?\s*(\d{4}-\d{2}-\d{2}|\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})/i';

        if (preg_match($pattern, $fullText, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @param  list<string>  $labels
     */
    private function labelledToken(string $fullText, array $labels): ?string
    {
        $labelPattern = implode('|', array_map(static fn (string $label): string => preg_quote($label, '/'), $labels));
        $pattern = '/(?:'.$labelPattern.')\s*[:.]?\s*([A-Z0-9][A-Z0-9\/\-]{2,40})/i';

        if (preg_match($pattern, $fullText, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }
}
