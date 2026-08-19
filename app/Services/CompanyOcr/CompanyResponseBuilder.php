<?php

namespace App\Services\CompanyOcr;

class CompanyResponseBuilder
{
    /**
     * @param  array<string,mixed>  $normalized
     * @param  array{company_type:?string,city:?string,state:?string,country:?string,errors:array<int,array{field:string,code:string,message:string}>}  $ruleResult
     * @param  array<int,array{field:string,code:string,message:string}>  $extraErrors
     * @param  array{overall:?float,company_name:?float,ssm_number:?float,address:?float,block:bool}|null  $confidence
     * @param  array<string,mixed>|null  $usage
     * @return array<string,mixed>
     */
    public function success(array $normalized, array $ruleResult, array $extraErrors = [], ?array $confidence = null, ?array $usage = null): array
    {
        $errors = array_merge($extraErrors, $ruleResult['errors']);

        if (($normalized['company_name'] ?? null) === null) {
            $errors[] = [
                'field' => 'company_name',
                'code' => 'unreadable_or_missing',
                'message' => 'Company name could not be reliably extracted.',
            ];
        }

        if (($normalized['ssm_number'] ?? null) === null && ($normalized['local_trading_license'] ?? null) === null) {
            $errors[] = [
                'field' => 'ssm_number',
                'code' => 'unreadable_or_missing',
                'message' => 'SSM number or local trading license could not be reliably extracted.',
            ];
        }

        $errors = $this->uniqueErrors($errors);
        $extracted = $this->extractedPayload($normalized);
        $derived = [
            'company_type' => $ruleResult['company_type'],
            'city' => $ruleResult['city'],
            'state' => $ruleResult['state'],
            'country' => $ruleResult['country'],
        ];

        return [
            'data' => [
                'extracted' => $extracted,
                'derived' => $derived,
            ],
            'validation' => [
                'status' => $this->statusFromErrors($errors, $extracted, $derived),
                'errors' => $errors,
                'warnings' => [],
            ],
            'confidence' => [
                'overall' => $confidence['overall'] ?? null,
                'company_name' => $confidence['company_name'] ?? null,
                'ssm_number' => $confidence['ssm_number'] ?? null,
                'address' => $confidence['address'] ?? null,
            ],
            'usage' => $usage ?? $this->emptyUsage(),
            'meta' => [
                'provider' => (string) config('ocr.provider', 'google_vision'),
                'document_type' => 'company',
                'stateless' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $usage
     * @return array<string,mixed>
     */
    public function failure(string $code, string $message, ?array $usage = null): array
    {
        return [
            'data' => [
                'extracted' => $this->extractedPayload([]),
                'derived' => [
                    'company_type' => null,
                    'city' => null,
                    'state' => null,
                    'country' => null,
                ],
            ],
            'validation' => [
                'status' => 'failed',
                'errors' => [[
                    'field' => 'system',
                    'code' => $code,
                    'message' => $message,
                ]],
                'warnings' => [],
            ],
            'confidence' => [
                'overall' => null,
                'company_name' => null,
                'ssm_number' => null,
                'address' => null,
            ],
            'usage' => $usage ?? $this->emptyUsage(),
            'meta' => [
                'provider' => (string) config('ocr.provider', 'google_vision'),
                'document_type' => 'company',
                'stateless' => true,
            ],
        ];
    }

    /**
     * @param  array{overall:?float,company_name:?float,ssm_number:?float,address:?float,block:bool}  $confidence
     * @param  array<string,mixed>|null  $usage
     * @return array<string,mixed>
     */
    public function lowConfidence(array $confidence, ?array $usage = null): array
    {
        $failure = $this->failure(
            'low_confidence_image',
            'Image confidence is too low. Please re-upload a clearer photo.',
            $usage
        );

        $failure['validation']['errors'][0]['field'] = 'image';
        $failure['confidence'] = [
            'overall' => $confidence['overall'],
            'company_name' => $confidence['company_name'],
            'ssm_number' => $confidence['ssm_number'],
            'address' => $confidence['address'],
        ];

        return $failure;
    }

    /**
     * @param  array<string,mixed>  $normalized
     * @return array<string,mixed>
     */
    private function extractedPayload(array $normalized): array
    {
        return [
            'company_name' => $normalized['company_name'] ?? null,
            'company_type' => $normalized['company_type'] ?? null,
            'ssm_number' => $normalized['ssm_number'] ?? null,
            'local_trading_license' => $normalized['local_trading_license'] ?? null,
            'local_trading_license_issuer' => $normalized['local_trading_license_issuer'] ?? null,
            'local_trading_license_expires_on' => $normalized['local_trading_license_expires_on'] ?? null,
            'tin_number' => $normalized['tin_number'] ?? null,
            'sst_number' => $normalized['sst_number'] ?? null,
            'msic_codes' => $normalized['msic_codes'] ?? [],
            'phone' => $normalized['phone'] ?? null,
            'country_code' => $normalized['country_code'] ?? null,
            'email' => $normalized['email'] ?? null,
            'address_line_1' => $normalized['address_line_1'] ?? null,
            'address_line_2' => $normalized['address_line_2'] ?? null,
            'address_line_3' => $normalized['address_line_3'] ?? null,
            'postcode' => $normalized['postcode'] ?? null,
            'city' => $normalized['city'] ?? null,
            'state' => $normalized['state'] ?? null,
            'country' => $normalized['country'] ?? null,
            'lhdn_employer_no' => $normalized['lhdn_employer_no'] ?? null,
            'epf_employer_no' => $normalized['epf_employer_no'] ?? null,
            'socso_employer_no' => $normalized['socso_employer_no'] ?? null,
            'hrdc_employer_no' => $normalized['hrdc_employer_no'] ?? null,
            'zakat_employer_no' => $normalized['zakat_employer_no'] ?? null,
            'jtk_employer_no' => $normalized['jtk_employer_no'] ?? null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyUsage(): array
    {
        $provider = (string) config('ocr.provider', 'google_vision');
        $pricePerUnit = (float) config('ocr.estimated_price_per_image_rm', 0.0);

        if ($provider === 'gemini') {
            $geminiPrice = (float) config('ocr.estimated_price_per_image_rm_gemini', 0.0);
            if ($geminiPrice > 0) {
                $pricePerUnit = $geminiPrice;
            }
        } elseif ($provider === 'google_vision') {
            $visionPrice = (float) config('ocr.estimated_price_per_image_rm_google_vision', 0.0);
            if ($visionPrice > 0) {
                $pricePerUnit = $visionPrice;
            }
        }

        return [
            'provider' => $provider,
            'unit' => (string) config('ocr.usage_unit', 'image'),
            'quantity' => 0,
            'currency' => (string) config('ocr.usage_currency', 'MYR'),
            'price_per_unit_rm' => round($pricePerUnit, 6),
            'estimated_cost_rm' => 0.0,
            'is_estimated' => true,
        ];
    }

    /**
     * @param  array<int,array{field:string,code:string,message:string}>  $errors
     * @param  array<string,mixed>  $extracted
     * @param  array<string,mixed>  $derived
     */
    private function statusFromErrors(array $errors, array $extracted, array $derived): string
    {
        $hasCore = ($extracted['company_name'] ?? null) !== null
            && (($extracted['ssm_number'] ?? null) !== null || ($extracted['local_trading_license'] ?? null) !== null);
        $hasStatutory = false;
        foreach (CompanyValueNormalizer::STATUTORY_FIELDS as $field) {
            if (($extracted[$field] ?? null) !== null) {
                $hasStatutory = true;
                break;
            }
        }

        $hasAny = $hasCore
            || $hasStatutory
            || ($extracted['tin_number'] ?? null) !== null
            || ($extracted['sst_number'] ?? null) !== null
            || ($extracted['address_line_1'] ?? null) !== null
            || ($extracted['email'] ?? null) !== null
            || ($extracted['phone'] ?? null) !== null
            || ($derived['company_type'] ?? null) !== null;

        if ($errors === [] && $hasCore) {
            return 'ok';
        }

        return $hasAny ? 'partial' : 'failed';
    }

    /**
     * @param  array<int,array{field:string,code:string,message:string}>  $errors
     * @return array<int,array{field:string,code:string,message:string}>
     */
    private function uniqueErrors(array $errors): array
    {
        $seen = [];
        $output = [];

        foreach ($errors as $error) {
            $key = $error['field'].'|'.$error['code'];
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $output[] = $error;
        }

        return $output;
    }
}
