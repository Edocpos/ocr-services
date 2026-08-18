<?php

namespace App\Services\IcOcr;

use Illuminate\Support\Facades\Cache;

class PostcodeDistrictResolver
{
    /**
    * @var array<string,array<int,array{state:string,district:string,location:string}>>|null
     */
    private static ?array $index = null;

    /**
     * @return array{state:?string,district:?string}
     */
    public function resolveFromPostcode(?string $postcode): array
    {
        $digits = preg_replace('/\D+/', '', (string) $postcode) ?? '';
        if (! preg_match('/^\d{5}$/', $digits)) {
            return ['state' => null, 'district' => null];
        }

        $records = $this->postcodeIndex()[$digits] ?? [];
        if ($records === []) {
            return ['state' => null, 'district' => null];
        }

        return [
            'state' => $this->singleValue($records, 'state'),
            'district' => $this->singleDistrict($records) ?? $this->singleValue($records, 'district'),
        ];
    }

    /**
     * @return array{district:?string,source:?string}
     */
    public function resolve(?string $address, ?string $derivedState): array
    {
        if ($address === null || trim($address) === '') {
            return ['district' => null, 'source' => null];
        }

        $postcode = $this->extractPostcode($address);
        if ($postcode === null) {
            return ['district' => null, 'source' => null];
        }

        $records = $this->postcodeIndex()[$postcode] ?? [];
        if ($records === []) {
            return ['district' => null, 'source' => 'postcode'];
        }

        // 1) Try state-constrained resolution first.
        $candidatePool = $records;
        if ($derivedState !== null) {
            $stateNorm = $this->normalizeState($derivedState);
            $byState = array_values(array_filter(
                $records,
                fn (array $row): bool => $this->normalizeState($row['state']) === $stateNorm
            ));

            if ($byState !== []) {
                $candidatePool = $byState;
            }

            $district = $this->matchDistrictFromAddress($address, $byState);
            if ($district !== null) {
                return ['district' => $district, 'source' => 'postcode'];
            }

            $district = $this->singleDistrict($byState);
            if ($district !== null) {
                return ['district' => $district, 'source' => 'postcode'];
            }
        }

        // 2) Try token match without state constraint.
        $district = $this->matchDistrictFromAddress($address, $candidatePool);
        if ($district !== null) {
            return ['district' => $district, 'source' => 'postcode'];
        }

        // 3) Fallback: if postcode has exactly one district regardless of state.
        $district = $this->singleDistrict($records);
        if ($district !== null) {
            return ['district' => $district, 'source' => 'postcode'];
        }

        return ['district' => null, 'source' => 'postcode'];
    }

    private function extractPostcode(string $address): ?string
    {
        if (preg_match('/\b(\d{5})\b/', $address, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @param  array<int,array{state:string,district:string,location:string}>  $records
     */
    private function singleValue(array $records, string $key): ?string
    {
        $values = [];
        foreach ($records as $row) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                $values[] = $value;
            }
        }

        $unique = array_values(array_unique($values));

        return count($unique) === 1 ? $unique[0] : ($unique[0] ?? null);
    }

    /**
    * @param array<int,array{state:string,district:string,location:string}> $records
     */
    private function singleDistrict(array $records): ?string
    {
        if ($records === []) {
            return null;
        }

        $districts = [];
        foreach ($records as $row) {
            $districts[] = trim($row['district']);
        }

        $unique = array_values(array_unique(array_filter($districts, fn (string $d): bool => $d !== '')));

        return count($unique) === 1 ? $unique[0] : null;
    }

    /**
     * @param array<int,array{state:string,district:string,location:string}> $records
     */
    private function matchDistrictFromAddress(string $address, array $records): ?string
    {
        if ($records === []) {
            return null;
        }

        $addressUpper = mb_strtoupper($address);

        // Prefer explicit district name mentions in address.
        $districtHits = [];
        foreach ($records as $row) {
            $district = trim($row['district']);
            if ($district === '') {
                continue;
            }

            if ($this->containsWord($addressUpper, $district)) {
                $districtHits[] = $district;
            }
        }

        $districtHits = array_values(array_unique($districtHits));
        if (count($districtHits) === 1) {
            return $districtHits[0];
        }

        // Then try location matches -> mapped district.
        $locationDistrictHits = [];
        foreach ($records as $row) {
            $location = trim($row['location']);
            if ($location === '' || mb_strlen($location) < 4) {
                continue;
            }

            if ($this->containsWord($addressUpper, $location)) {
                $locationDistrictHits[] = trim($row['district']);
            }
        }

        $locationDistrictHits = array_values(array_unique(array_filter($locationDistrictHits, fn (string $d): bool => $d !== '')));

        return count($locationDistrictHits) === 1 ? $locationDistrictHits[0] : null;
    }

    private function containsWord(string $haystackUpper, string $needle): bool
    {
        $needleUpper = mb_strtoupper(trim($needle));
        if ($needleUpper === '') {
            return false;
        }

        return preg_match('/\b'.preg_quote($needleUpper, '/').'\b/u', $haystackUpper) === 1;
    }

    /**
    * @return array<string,array<int,array{state:string,district:string,location:string}>>
     */
    private function postcodeIndex(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        $path = (string) config('ocr.postcode_map_path');
        if ($path === '' || ! is_file($path)) {
            self::$index = [];

            return self::$index;
        }

        $fingerprint = md5($path.'|'.(string) @filemtime($path).'|'.(string) @filesize($path));
        $cacheKey = 'ocr:postcode_index:'.$fingerprint;

        /** @var array<string,array<int,array{state:string,district:string,location:string}>> $cached */
        $cached = Cache::rememberForever($cacheKey, function () use ($path): array {
            return $this->loadIndexFromCsv($path);
        });

        self::$index = $cached;

        return self::$index;
    }

    /**
    * @return array<string,array<int,array{state:string,district:string,location:string}>>
     */
    private function loadIndexFromCsv(string $path): array
    {

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $index = [];

        try {
            $header = fgetcsv($handle);
            if (! is_array($header)) {
                return [];
            }

            $columns = array_flip(array_map(
                static function (string $h): string {
                    $h = preg_replace('/^\xEF\xBB\xBF/', '', $h) ?? $h;

                    return strtolower(trim($h));
                },
                $header
            ));
            $postcodeCol = $columns['postcode'] ?? null;
            $stateCol = $columns['state'] ?? null;
            $districtCol = $columns['district'] ?? null;
            $locationCol = $columns['location'] ?? null;

            if (! is_int($postcodeCol) || ! is_int($stateCol) || ! is_int($districtCol) || ! is_int($locationCol)) {
                return [];
            }

            while (($row = fgetcsv($handle)) !== false) {
                $postcode = trim((string) ($row[$postcodeCol] ?? ''));
                $state = trim((string) ($row[$stateCol] ?? ''));
                $district = trim((string) ($row[$districtCol] ?? ''));
                $location = trim((string) ($row[$locationCol] ?? ''));

                if (! preg_match('/^\d{5}$/', $postcode)) {
                    continue;
                }

                if ($state === '' || $district === '') {
                    continue;
                }

                $index[$postcode][] = [
                    'state' => $state,
                    'district' => $district,
                    'location' => $location,
                ];
            }
        } finally {
            fclose($handle);
        }

        return $index;
    }

    private function normalizeState(string $state): string
    {
        $state = strtoupper(trim($state));

        return match ($state) {
            'WP KUALA LUMPUR', 'W.P. KUALA LUMPUR', 'KUALA LUMPUR' => 'WP KUALA LUMPUR',
            'WP LABUAN', 'W.P. LABUAN', 'LABUAN' => 'WP LABUAN',
            'WP PUTRAJAYA', 'W.P. PUTRAJAYA', 'PUTRAJAYA' => 'WP PUTRAJAYA',
            'PULAU PINANG', 'PENANG' => 'PULAU PINANG',
            default => $state,
        };
    }
}
