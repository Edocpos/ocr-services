<?php

namespace Tests\Unit\CompanyOcr;

use App\Services\CompanyOcr\CompanyCorporateDocumentClassifier;
use Tests\TestCase;

class CompanyCorporateDocumentClassifierTest extends TestCase
{
    private CompanyCorporateDocumentClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = new CompanyCorporateDocumentClassifier;
    }

    public function test_it_prefers_an_explicit_catalog_type_over_other_text(): void
    {
        $type = $this->classifier->classify('section_14', 'NOTICE OF REGISTRATION');

        $this->assertSame('section_14', $type);
    }

    public function test_it_normalizes_a_labelled_section_68_heading(): void
    {
        $type = $this->classifier->classify('Section 68 — Annual Return', '');

        $this->assertSame('section_68', $type);
    }

    public function test_it_matches_section_58_236_2_before_the_shorter_sections(): void
    {
        $type = $this->classifier->classify(null, 'Section 58 / 236(2) — First Secretary Appointment');

        $this->assertSame('section_58_236_2', $type);
    }

    public function test_it_leaves_a_company_profile_unclassified(): void
    {
        $type = $this->classifier->classify(null, "NAMA SYARIKAT: Template Demo Sdn Bhd\nNO. SYARIKAT: 202600000999");

        $this->assertNull($type);
    }

    public function test_it_reads_the_annual_return_year_beside_section_68(): void
    {
        $hints = $this->classifier->extractRegisterHints('Section 68 (2026)\nDOCUMENT DATE: 01/03/2026');

        $this->assertSame('2026', $hints['annual_return_year']);
        $this->assertSame('01/03/2026', $hints['document_date']);
    }
}
