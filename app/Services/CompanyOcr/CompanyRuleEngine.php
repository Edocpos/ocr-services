<?php

namespace App\Services\CompanyOcr;

use App\Services\IcOcr\PostcodeDistrictResolver;

class CompanyRuleEngine
{
    public function __construct(
        private readonly CompanyValueNormalizer $normalizer,
        private readonly PostcodeDistrictResolver $districtResolver,
    ) {
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array{
     *   company_type:?string,
     *   city:?string,
     *   state:?string,
     *   country:?string,
     *   errors:array<int,array{field:string,code:string,message:string}>
     * }
     */
    public function derive(array $raw, array $normalized): array
    {
        $errors = [];

        $companyType = $normalized['company_type']
            ?? $this->normalizer->inferCompanyTypeFromName($normalized['company_name'] ?? $raw['company_name'] ?? null);

        $city = $normalized['city'];
        $state = $normalized['state'];
        $country = $normalized['country'] ?? 'Malaysia';

        $location = $this->districtResolver->resolveFromPostcode($normalized['postcode'] ?? null);
        if ($city === null && ($location['district'] ?? null) !== null) {
            $city = $location['district'];
        }
        if ($state === null && ($location['state'] ?? null) !== null) {
            $state = $location['state'];
        }

        if (($raw['ssm_number'] ?? null) !== null && ($normalized['ssm_number'] ?? null) === null) {
            $errors[] = [
                'field' => 'ssm_number',
                'code' => 'invalid_ssm_number',
                'message' => 'SSM number format is invalid after normalization.',
            ];
        }

        if (($raw['email'] ?? null) !== null && ($normalized['email'] ?? null) === null) {
            $errors[] = [
                'field' => 'email',
                'code' => 'invalid_email',
                'message' => 'Email address format is invalid after normalization.',
            ];
        }

        return [
            'company_type' => $companyType,
            'city' => $city,
            'state' => $state,
            'country' => $country,
            'errors' => $errors,
        ];
    }
}
