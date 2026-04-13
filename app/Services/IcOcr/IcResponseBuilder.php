<?php

namespace App\Services\IcOcr;

class IcResponseBuilder
{
    /**
     * @param array{ic_number:?string,name:?string,address:?string} $raw
     * @param array{ic_number:?string,name:?string,address:?string} $normalized
    * @param array{is_valid:bool,birth_date:?string,gender:?string,state:?string,district:?string,district_source:?string,errors:array<int,array{field:string,code:string,message:string}>} $ruleResult
     * @param array<int,array{field:string,code:string,message:string}> $extraErrors
        * @param array{overall:?float,ic_number:?float,name:?float,address:?float,birth_date:?float,gender:?float,state:?float,district:?float,block:bool}|null $confidence
      * @param array{provider:string,unit:string,quantity:int,currency:string,price_per_unit_rm:float,estimated_cost_rm:float,is_estimated:bool}|null $usage
     * @return array<string,mixed>
     */
          public function success(array $raw, array $normalized, array $ruleResult, array $extraErrors = [], ?array $confidence = null, ?array $usage = null): array
    {
        $errors = array_merge($extraErrors, $ruleResult['errors']);

        if ($raw['name'] === null || $normalized['name'] === null) {
            $errors[] = [
                'field' => 'name',
                'code' => 'unreadable_or_missing',
                'message' => 'Name could not be reliably extracted.',
            ];
        }

        if ($raw['address'] === null || $normalized['address'] === null) {
            $errors[] = [
                'field' => 'address',
                'code' => 'unreadable_or_missing',
                'message' => 'Address could not be reliably extracted.',
            ];
        }

        $icDisplay = $normalized['ic_number'];
        if ($raw['ic_number'] === null) {
            $errors[] = [
                'field' => 'ic_number',
                'code' => 'unreadable_or_missing',
                'message' => 'IC number could not be reliably extracted.',
            ];
        } elseif ($icDisplay === null) {
            $errors[] = [
                'field' => 'ic_number',
                'code' => 'invalid_ic_number',
                'message' => 'IC number format is invalid after normalization.',
            ];
        }

        $errors = $this->uniqueErrors($errors);
        $status = $this->statusFromErrors($errors, $normalized, $ruleResult);

        return [
            'data' => [
                'extracted' => [
                    'ic_number' => $icDisplay,
                    'name' => $normalized['name'],
                    'address' => $normalized['address'],
                ],
                'derived' => [
                    'birth_date' => $ruleResult['birth_date'],
                    'gender' => $ruleResult['gender'],
                    'state' => $ruleResult['state'],
                    'district' => $ruleResult['district'],
                ],
            ],
            'validation' => [
                'status' => $status,
                'errors' => $errors,
                'warnings' => [],
            ],
            'confidence' => [
                'overall' => $confidence['overall'] ?? null,
                'ic_number' => $confidence['ic_number'] ?? null,
                'name' => $confidence['name'] ?? null,
                'address' => $confidence['address'] ?? null,
                'birth_date' => $confidence['birth_date'] ?? null,
                'gender' => $confidence['gender'] ?? null,
                'state' => $confidence['state'] ?? null,
                'district' => $confidence['district'] ?? null,
            ],
            'usage' => $usage ?? $this->emptyUsage(),
            'meta' => [
                'provider' => (string) config('ocr.provider', 'google_vision'),
                'stateless' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function failure(string $code, string $message, ?array $usage = null): array
    {
        return [
            'data' => [
                'extracted' => [
                    'ic_number' => null,
                    'name' => null,
                    'address' => null,
                ],
                'derived' => [
                    'birth_date' => null,
                    'gender' => null,
                    'state' => null,
                    'district' => null,
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
                'ic_number' => null,
                'name' => null,
                'address' => null,
                'birth_date' => null,
                'gender' => null,
                'state' => null,
                'district' => null,
            ],
            'usage' => $usage ?? $this->emptyUsage(),
            'meta' => [
                'provider' => (string) config('ocr.provider', 'google_vision'),
                'stateless' => true,
            ],
        ];
    }

    /**
     * @param array{overall:?float,ic_number:?float,name:?float,address:?float,birth_date:?float,gender:?float,state:?float,district:?float,block:bool} $confidence
     * @param array{provider:string,unit:string,quantity:int,currency:string,price_per_unit_rm:float,estimated_cost_rm:float,is_estimated:bool}|null $usage
     * @return array<string,mixed>
     */
    public function lowConfidence(array $confidence, ?array $usage = null): array
    {
        return [
            'data' => [
                'extracted' => [
                    'ic_number' => null,
                    'name' => null,
                    'address' => null,
                ],
                'derived' => [
                    'birth_date' => null,
                    'gender' => null,
                    'state' => null,
                    'district' => null,
                ],
            ],
            'validation' => [
                'status' => 'failed',
                'errors' => [[
                    'field' => 'image',
                    'code' => 'low_confidence_image',
                    'message' => 'Image confidence is too low. Please re-upload a clearer photo.',
                ]],
                'warnings' => [],
            ],
            'confidence' => [
                'overall' => $confidence['overall'],
                'ic_number' => $confidence['ic_number'],
                'name' => $confidence['name'],
                'address' => $confidence['address'],
                'birth_date' => $confidence['birth_date'],
                'gender' => $confidence['gender'],
                'state' => $confidence['state'],
                'district' => $confidence['district'],
            ],
            'usage' => $usage ?? $this->emptyUsage(),
            'meta' => [
                'provider' => (string) config('ocr.provider', 'google_vision'),
                'stateless' => true,
            ],
        ];
    }

    /**
     * @return array{provider:string,unit:string,quantity:int,currency:string,price_per_unit_rm:float,estimated_cost_rm:float,is_estimated:bool}
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
     * @param array<int,array{field:string,code:string,message:string}> $errors
     * @param array{ic_number:?string,name:?string,address:?string} $normalized
        * @param array{is_valid:bool,birth_date:?string,gender:?string,state:?string,district:?string,district_source:?string,errors:array<int,array{field:string,code:string,message:string}>} $ruleResult
     */
    private function statusFromErrors(array $errors, array $normalized, array $ruleResult): string
    {
        if ($errors === []) {
            return 'ok';
        }

        $hasAnyData = $normalized['ic_number'] !== null
            || $normalized['name'] !== null
            || $normalized['address'] !== null
            || $ruleResult['birth_date'] !== null
            || $ruleResult['gender'] !== null
            || $ruleResult['state'] !== null
            || $ruleResult['district'] !== null;

        return $hasAnyData ? 'partial' : 'failed';
    }

    /**
     * @param array<int,array{field:string,code:string,message:string}> $errors
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
