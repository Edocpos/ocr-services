<?php

namespace Tests\Unit\CompanyOcr;

use App\Services\CompanyOcr\CompanyFormFieldFitter;
use Tests\TestCase;

class CompanyFormFieldFitterTest extends TestCase
{
    public function test_it_continues_a_long_address_onto_the_next_lines(): void
    {
        $fitter = new CompanyFormFieldFitter;

        $fitted = $fitter->fit([
            'address_line_1' => 'No 10, Jalan Tun Razak',
            'address_line_2' => 'Tingkat 5, Menara ABC',
            'address_line_3' => null,
            'city' => 'Kuala Lumpur 50000',
            'state' => 'Wilayah Persekutuan',
            'country' => 'Malaysia',
            'local_trading_license_issuer' => 'Dewan Bandaraya Kota Kinabalu',
            'lhdn_employer_no' => 'E12345678901',
            'phone' => '012-345 6789',
            'tin_number' => 'C123456789012345',
            'sst_number' => 'W10-1901-32000001-EXTRA',
        ]);

        $this->assertSame('No 10, Jalan', $fitted['address_line_1']);
        $this->assertLessThanOrEqual(15, mb_strlen((string) $fitted['address_line_1']));
        $this->assertLessThanOrEqual(30, mb_strlen((string) $fitted['address_line_2']));
        $this->assertLessThanOrEqual(30, mb_strlen((string) $fitted['address_line_3']));
        $this->assertStringContainsString('Tun Razak', (string) $fitted['address_line_2']);
        $this->assertStringContainsString('Menara', (string) $fitted['address_line_2'].' '.(string) $fitted['address_line_3']);
        $this->assertSame('Kuala Lumpur', $fitted['city']);
        $this->assertSame('Dewan', $fitted['local_trading_license_issuer']);
        $this->assertSame('E1234567890', $fitted['lhdn_employer_no']);
        $this->assertSame('0123456789', $fitted['phone']);
        $this->assertSame(10, strlen((string) $fitted['phone']));
        $this->assertSame('C1234567890123', $fitted['tin_number']);
        $this->assertSame(20, strlen((string) $fitted['sst_number']));
    }

    public function test_it_leaves_values_that_already_fit(): void
    {
        $fitter = new CompanyFormFieldFitter;

        $fitted = $fitter->fitAddressLines('No 1, Jalan', 'Tingkat 5', null);

        $this->assertSame(['No 1, Jalan', 'Tingkat 5', null], $fitted);
    }
}
