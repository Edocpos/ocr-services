<?php

namespace App\Services\AccountingOcr;

class AccountCodeRegistry
{
    /** @var list<string> */
    private const PREFIXES = [
        'BS/NA/PPE/LAND', 'BS/NA/PPE/BLDG', 'BS/NA/PPE/INDB', 'BS/NA/PPE/OFFB',
        'BS/NA/PPE/PLNT', 'BS/NA/PPE/MCHN', 'BS/NA/PPE/HVYE', 'BS/NA/PPE/MTVE',
        'BS/NA/PPE/FNFT', 'BS/NA/PPE/OFFE', 'BS/NA/PPE/COMP', 'BS/NA/PPE/SOFT', 'BS/NA/PPE/RENO',
        'BS/NA/IVM/ILND', 'BS/NA/IVM/IBDG', 'BS/NA/IVM/IQTS', 'BS/NA/IVM/IUTS',
        'BS/CA/IVT/TSTK', 'BS/CA/IVT/CSTK', 'BS/CA/TRV/TRDB', 'BS/CA/TRV/TRDP',
        'BS/CA/ORV/OTDB', 'BS/CA/ORV/OTDP', 'BS/CA/ORV/PRMT', 'BS/CA/ORV/DIRA',
        'BS/CA/CTX/CYTX', 'BS/CA/CTX/PYPX', 'BS/CA/CNB/BANK', 'BS/CA/CNB/CASH',
        'BS/EQ/CAP/SHCP', 'BS/EQ/CAP/POCP', 'BS/EQ/CAP/PTCP', 'BS/EQ/CPR/SHPM',
        'BS/EQ/CPR/RVSP', 'BS/EQ/RVR/APNL', 'BS/NL/NBR/NCTL', 'BS/NL/NFL/NCFL',
        'BS/NL/DTX/NCDX', 'BS/CL/TPY/TPTC', 'BS/CL/TPY/TPTD', 'BS/CL/OPY/OPCR',
        'BS/CL/OPY/OPDC', 'BS/CL/OPY/OPCC', 'BS/CL/SFL/STFL', 'BS/CL/CTL/CRTX',
        'BS/CL/CTL/STDF', 'BS/CL/CTL/SNTP', 'BS/CL/SBR/TRFL', 'BS/CL/SBR/OVDF', 'BS/CL/SBR/SHTL',
        'PL/OI/RIN/SLIC', 'PL/OI/RIN/SVIC', 'PL/OI/OIN/DBMT', 'PL/OI/OIN/RBMT',
        'PL/OI/OIN/OVTC', 'PL/OI/OIN/BINC', 'PL/OI/OIN/RBDR', 'PL/OI/OIN/GPME',
        'PL/OI/OIN/RTNC', 'PL/OI/OIN/ISCL', 'PL/OI/OIN/BDRC', 'PL/OI/OIN/OTNC',
        'PL/OE/OEX/CSSL', 'PL/OE/OEX/DTEX', 'PL/OE/OEX/MKEX', 'PL/OE/OEX/OPEX',
        'PL/OE/OEX/DPRC', 'PL/OE/OEX/GNEX', 'PL/FE/FEX/OVNT', 'PL/FE/FEX/FLNT',
        'PL/FE/FEX/TLNT', 'PL/FE/FEX/OVNX', 'PL/FE/FEX/TDFT', 'PL/FE/FEX/OTNT',
        'PL/TX/ITX/TCYX', 'PL/TX/ITX/TXPL', 'PL/TX/ITX/TDTX', 'PL/TX/RPX/PRGX',
        'PL/TX/RPX/CPGX', 'PL/TX/OTX/OTEX',
    ];

    /** @var array<string, string> */
    private const ALIASES = [
        'PL/TI/TIN/SLIC' => 'PL/OI/RIN/SLIC',
        'PL/TI/TIN/SVIC' => 'PL/OI/RIN/SVIC',
        'PL/OI/TIN/SLIC' => 'PL/OI/RIN/SLIC',
        'PL/OI/TIN/SVIC' => 'PL/OI/RIN/SVIC',
        'PL/TE/TEX/CSSL' => 'PL/OE/OEX/CSSL',
        'PL/TE/TEX/DTEX' => 'PL/OE/OEX/DTEX',
        'PL/TE/TEX/MKEX' => 'PL/OE/OEX/MKEX',
        'PL/TE/TEX/OPEX' => 'PL/OE/OEX/OPEX',
        'PL/OE/TEX/CSSL' => 'PL/OE/OEX/CSSL',
        'PL/OE/TEX/DTEX' => 'PL/OE/OEX/DTEX',
        'PL/OE/TEX/MKEX' => 'PL/OE/OEX/MKEX',
        'PL/OE/TEX/OPEX' => 'PL/OE/OEX/OPEX',
    ];

    public function normalize(?string $prefix): ?string
    {
        $prefix = strtoupper(trim((string) $prefix));
        $prefix = rtrim($prefix, '/');
        $prefix = self::ALIASES[$prefix] ?? $prefix;

        return in_array($prefix, self::PREFIXES, true) ? $prefix : null;
    }

    /** @param list<array<string, mixed>> $accounts */
    public function preferredPrefix(string $canonical, array $accounts): string
    {
        foreach ($accounts as $account) {
            $parts = explode('/', strtoupper((string) ($account['code'] ?? '')));
            if (count($parts) !== 5) {
                continue;
            }

            $rawPrefix = implode('/', array_slice($parts, 0, 4));
            if ($this->normalize($rawPrefix) === $canonical) {
                return $rawPrefix;
            }
        }

        return $canonical;
    }

    /** @return array{type:string,subtype:string,normal_balance:string} */
    public function metadata(string $canonical): array
    {
        $parts = explode('/', $canonical);
        $type = match ($parts[0].'/'.$parts[1]) {
            'BS/NA', 'BS/CA' => 'asset',
            'BS/NL', 'BS/CL' => 'liability',
            'BS/EQ' => 'equity',
            'PL/OI' => 'revenue',
            default => 'expense',
        };

        $subtype = match ($canonical) {
            'BS/CA/CNB/BANK' => 'bank',
            'BS/CA/CNB/CASH' => 'cash',
            'BS/CA/TRV/TRDB' => 'accounts_receivable',
            'BS/CL/TPY/TPTC' => 'accounts_payable',
            'BS/CA/CTX/CYTX', 'BS/CA/CTX/PYPX' => 'input_tax',
            'BS/CL/CTL/CRTX', 'BS/CL/CTL/STDF', 'BS/CL/CTL/SNTP' => 'output_tax',
            default => 'other',
        };

        return [
            'type' => $type,
            'subtype' => $subtype,
            'normal_balance' => in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit',
        ];
    }
}
