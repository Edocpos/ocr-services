<?php

namespace App\Services\IcOcr;

class IcConfidenceEvaluator
{
    /**
     * @param array{full_text:string,lines:array<int,string>,overall_confidence:?float} $ocrPayload
     * @param array{ic_number:?string,name:?string,address:?string} $raw
     * @param array{ic_number:?string,name:?string,address:?string} $normalized
     * @param array{is_valid:bool,birth_date:?string,gender:?string,state:?string,district:?string,district_source:?string,errors:array<int,array{field:string,code:string,message:string}>} $ruleResult
     * @return array{overall:?float,ic_number:?float,name:?float,address:?float,birth_date:?float,gender:?float,state:?float,district:?float,block:bool}
     */
    public function evaluate(array $ocrPayload, array $raw, array $normalized, array $ruleResult): array
    {
        $overall = isset($ocrPayload['overall_confidence']) && is_numeric($ocrPayload['overall_confidence'])
            ? (float) $ocrPayload['overall_confidence']
            : null;

        $base = $overall ?? 0.72;

        $ic = $this->scoreIc($base, $raw['ic_number'], $normalized['ic_number'], $ruleResult['is_valid']);
        $name = $this->scoreName($base, $normalized['name']);
        $address = $this->scoreAddress($base, $normalized['address']);

        $birthDate = $ruleResult['birth_date'] !== null ? $ic : null;
        $gender = $ruleResult['gender'] !== null ? $ic : null;
        $state = $ruleResult['state'] !== null ? $ic : null;
        $district = $ruleResult['district'] !== null ? min(1.0, max(0.0, ($address ?? 0.0) + 0.06)) : null;

        return [
            'overall' => $overall,
            'ic_number' => $ic,
            'name' => $name,
            'address' => $address,
            'birth_date' => $birthDate,
            'gender' => $gender,
            'state' => $state,
            'district' => $district,
            'block' => $this->shouldBlock($overall, $ic, $name, $address),
        ];
    }

    private function shouldBlock(?float $overall, ?float $ic, ?float $name, ?float $address): bool
    {
        if (! (bool) config('ocr.enable_confidence_gate', true)) {
            return false;
        }

        $minOverall = (float) config('ocr.confidence_min_overall', 0.55);
        $minField = (float) config('ocr.confidence_min_field', 0.50);

        if ($overall !== null && $overall < $minOverall) {
            return true;
        }

        // When Vision API gives no confidence score (API-key mode), the fixed base score of 0.72
        // is not meaningful enough to approve a result where the IC itself was not found at all.
        if ($overall === null && ($ic === null || $ic === 0.0)) {
            return true;
        }

        foreach ([$ic, $name, $address] as $requiredFieldScore) {
            if ($requiredFieldScore === null || $requiredFieldScore < $minField) {
                return true;
            }
        }

        return false;
    }

    private function scoreIc(float $base, ?string $rawIc, ?string $normalizedIc, bool $isRuleValid): float
    {
        if ($rawIc === null || $normalizedIc === null) {
            return 0.0;
        }

        $score = $base + 0.10;

        // Bonus if OCR preserved the dashed format (XXXXXX-XX-XXXX) — signals a clear, readable image.
        // Check rawIc because normalizeIcDigits() strips dashes to 12 plain digits.
        if (preg_match('/^\d{6}-\d{2}-\d{4}$/', $rawIc) === 1) {
            $score += 0.06;
        }

        if (! $isRuleValid) {
            $score -= 0.20;
        }

        return min(1.0, max(0.0, $score));
    }

    private function scoreName(float $base, ?string $name): float
    {
        if ($name === null) {
            return 0.0;
        }

        $score = $base + 0.08;
        if (preg_match('/\d/', $name) === 1) {
            $score -= 0.25;
        }

        return min(1.0, max(0.0, $score));
    }

    private function scoreAddress(float $base, ?string $address): float
    {
        if ($address === null) {
            return 0.0;
        }

        $score = $base + 0.05;

        if (preg_match('/\b\d{5}\b/', $address) === 1) {
            $score += 0.10;
        }

        if (mb_strlen($address) < 10) {
            $score -= 0.20;
        }

        return min(1.0, max(0.0, $score));
    }
}
