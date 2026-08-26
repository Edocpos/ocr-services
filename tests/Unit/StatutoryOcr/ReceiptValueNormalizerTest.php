<?php

namespace Tests\Unit\StatutoryOcr;

use App\Services\StatutoryOcr\ReceiptValueNormalizer;
use App\Services\StatutoryOcr\SchemeDetector;
use Tests\TestCase;

class ReceiptValueNormalizerTest extends TestCase
{
    public function test_it_normalizes_malaysian_currency(): void
    {
        $normalizer = new ReceiptValueNormalizer;

        $this->assertSame(318.45, $normalizer->normalizeAmount('RM318.45'));
        $this->assertSame(1234.56, $normalizer->normalizeAmount('RM 1,234.56'));
        $this->assertSame(50.0, $normalizer->normalizeAmount('RM50.00'));
        $this->assertNull($normalizer->normalizeAmount(null));
    }

    public function test_it_normalizes_dates_and_periods(): void
    {
        $normalizer = new ReceiptValueNormalizer;

        $this->assertSame('06/08/2026', $normalizer->normalizeDate('06/08/2026'));
        $this->assertSame('06/08/2026', $normalizer->normalizeDate('2026-08-06'));
        $this->assertSame('07/2026', $normalizer->normalizePeriod('07/2026'));
        $this->assertSame('07/2026', $normalizer->normalizePeriod('2026-07'));
        $this->assertSame('F9702103813F', $normalizer->normalizeIdentifier('F9702103813F'));
    }

    public function test_detector_does_not_confuse_acr_and_ecr(): void
    {
        $detector = new SchemeDetector;

        $this->assertSame('eis', $detector->detect('Caruman Bulanan(ECR082260127652-07/2026)'));
        $this->assertSame('socso', $detector->detect('Caruman Bulanan(ACR082260152714-07/2026)'));
        $this->assertNull($detector->detect('KWSP contribution receipt July 2026'));
    }
}
