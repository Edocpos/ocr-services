<?php

namespace App\Services\CompanyOcr;

class CompanyConfidenceEvaluator
{
    /**
     * @param  array{full_text?:string,lines?:array<int,string>,overall_confidence:?float}  $ocrPayload
     * @param  array<string,mixed>  $normalized
     * @return array{overall:?float,company_name:?float,ssm_number:?float,address:?float,block:bool}
     */
    public function evaluate(array $ocrPayload, array $normalized): array
    {
        $overall = isset($ocrPayload['overall_confidence']) && is_numeric($ocrPayload['overall_confidence'])
            ? (float) $ocrPayload['overall_confidence']
            : null;

        $base = $overall ?? 0.72;
        $companyName = $this->scorePresent($base, $normalized['company_name'] ?? null, 0.08);
        $ssm = $this->scorePresent($base, $normalized['ssm_number'] ?? null, 0.10);
        $address = $this->scoreAddress($base, $normalized);

        return [
            'overall' => $overall,
            'company_name' => $companyName,
            'ssm_number' => $ssm,
            'address' => $address,
            'block' => $this->shouldBlock($overall, $normalized),
        ];
    }

    /**
     * @param  array<string,mixed>  $normalized
     */
    private function shouldBlock(?float $overall, array $normalized): bool
    {
        if (! (bool) config('ocr.enable_confidence_gate', true)) {
            return false;
        }

        $minOverall = (float) config('ocr.confidence_min_overall', 0.55);

        if ($overall !== null && $overall < $minOverall) {
            return true;
        }

        $hasUsableField = ($normalized['company_name'] ?? null) !== null
            || ($normalized['ssm_number'] ?? null) !== null
            || ($normalized['local_trading_license'] ?? null) !== null
            || ($normalized['tin_number'] ?? null) !== null
            || ($normalized['address_line_1'] ?? null) !== null
            || ($normalized['lhdn_employer_no'] ?? null) !== null
            || ($normalized['epf_employer_no'] ?? null) !== null
            || ($normalized['socso_employer_no'] ?? null) !== null;

        if ($overall === null && ! $hasUsableField) {
            return true;
        }

        return false;
    }

    private function scorePresent(float $base, ?string $value, float $bonus): ?float
    {
        if ($value === null) {
            return null;
        }

        return min(1.0, max(0.0, $base + $bonus));
    }

    /**
     * @param  array<string,mixed>  $normalized
     */
    private function scoreAddress(float $base, array $normalized): ?float
    {
        $line1 = $normalized['address_line_1'] ?? null;
        if ($line1 === null) {
            return null;
        }

        $score = $base + 0.05;
        if (($normalized['postcode'] ?? null) !== null) {
            $score += 0.10;
        }

        if (mb_strlen((string) $line1) < 6) {
            $score -= 0.20;
        }

        return min(1.0, max(0.0, $score));
    }
}
