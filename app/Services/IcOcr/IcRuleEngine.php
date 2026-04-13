<?php

namespace App\Services\IcOcr;

class IcRuleEngine
{
    /**
     * Malaysian IC place-of-birth/registration state codes (digits 7–8).
     *
     * @var array<string,string>
     */
    private const STATE_CODES = [
        // Primary state registration codes (01–16)
        '01' => 'Johor',
        '02' => 'Kedah',
        '03' => 'Kelantan',
        '04' => 'Melaka',
        '05' => 'Negeri Sembilan',
        '06' => 'Pahang',
        '07' => 'Pulau Pinang',
        '08' => 'Perak',
        '09' => 'Perlis',
        '10' => 'Selangor',
        '11' => 'Terengganu',
        '12' => 'Sabah',
        '13' => 'Sarawak',
        '14' => 'WP Kuala Lumpur',
        '15' => 'WP Labuan',
        '16' => 'WP Putrajaya',
        // Extended alternate series (21–36) — same state ordering, second registration scheme
        '21' => 'Johor',
        '22' => 'Kedah',
        '23' => 'Kelantan',
        '24' => 'Melaka',
        '25' => 'Negeri Sembilan',
        '26' => 'Pahang',
        '27' => 'Pulau Pinang',
        '28' => 'Perak',
        '29' => 'Perlis',
        '30' => 'Selangor',
        '31' => 'Terengganu',
        '32' => 'Sabah',
        '33' => 'Sarawak',
        '34' => 'WP Kuala Lumpur',
        '35' => 'WP Labuan',
        '36' => 'WP Putrajaya',
        // Special codes
        '82' => 'Luar Negara',    // Born outside Malaysia
    ];

    /**
     * @return array{is_valid:bool,birth_date:?string,gender:?string,state:?string,errors:array<int,array{field:string,code:string,message:string}>}
     */
    public function derive(?string $icDigits): array
    {
        $errors = [];

        if ($icDigits === null || strlen($icDigits) !== 12) {
            $errors[] = [
                'field' => 'ic_number',
                'code' => 'invalid_ic_number',
                'message' => 'IC number must be 12 digits after normalization.',
            ];

            return [
                'is_valid' => false,
                'birth_date' => null,
                'gender' => null,
                'state' => null,
                'errors' => $errors,
            ];
        }

        $yy = (int) substr($icDigits, 0, 2);
        $mm = (int) substr($icDigits, 2, 2);
        $dd = (int) substr($icDigits, 4, 2);

        $currentYearYY = (int) now()->format('y');
        $century = $yy <= $currentYearYY ? 2000 : 1900;
        $year = $century + $yy;

        if (! checkdate($mm, $dd, $year)) {
            $errors[] = [
                'field' => 'birth_date',
                'code' => 'invalid_birth_date_from_ic',
                'message' => 'IC birth date segment is not a valid calendar date.',
            ];

            return [
                'is_valid' => false,
                'birth_date' => null,
                'gender' => null,
                'state' => null,
                'errors' => $errors,
            ];
        }

        $birthDate = sprintf('%04d-%02d-%02d', $year, $mm, $dd);
        $lastDigit = (int) substr($icDigits, -1);
        $gender = $lastDigit % 2 === 0 ? 'female' : 'male';
        $placeCode = substr($icDigits, 6, 2);
        $state = self::STATE_CODES[$placeCode] ?? null;

        return [
            'is_valid' => true,
            'birth_date' => $birthDate,
            'gender' => $gender,
            'state' => $state,
            'errors' => [],
        ];
    }
}
