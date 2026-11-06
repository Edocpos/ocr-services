<?php

namespace Tests\Feature;

use App\Contracts\Ocr\AccountingOcrClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AccountingOcrApiTest extends TestCase
{
    public function test_bank_transfer_advice_uses_printed_parties_and_supplied_ledgers_on_rescan(): void
    {
        $accounts = [
            ['code' => 'BS/CA/CNB/BANK/10000', 'name' => 'CASH AT BANK', 'type' => 'asset', 'subtype' => 'bank'],
            ['code' => 'BS/CA/TRV/TRDB/10000', 'name' => 'ACME CUSTOMER SDN BHD', 'type' => 'asset', 'subtype' => 'trade_receivable'],
        ];
        $results = [];
        foreach (['Example Bank Berhad', 'ACME CUSTOMER SDN BHD'] as $modelParty) {
            $model = $this->payload();
            $model['document_kind'] = 'receipt';
            $model['routing_document_kind'] = 'unknown';
            $model['buyer_name'] = 'DEMO COMPANY PLT';
            $model['issuer_name'] = $modelParty;
            $model['counterparty'] = $modelParty;
            $model['service_periods'] = [[
                'description' => 'Payment ref Q1', 'amount' => 100.00,
                'start_date' => null, 'end_date' => null, 'period_kind' => 'unknown',
                'evidence' => 'Payment ref Q1', 'prepayment_candidate' => true,
            ]];
            $this->app->bind(AccountingOcrClient::class, fn () => new class($model) implements AccountingOcrClient
            {
                public function __construct(private readonly array $model) {}

                public function extract(string $documentContent, string $mimeType, array $accounts, ?string $companyContext = null, array $context = []): array
                {
                    return $this->model;
                }
            });
            $file = new UploadedFile(base_path('tests/Fixtures/receipts/bank-transfer-approval.pdf'), 'bank-transfer-approval.pdf', 'application/pdf', null, true);
            $response = $this->post('/api/ocr/accounting', [
                'document' => $file, 'company' => ['name' => 'DEMO COMPANY PLT'], 'accounts' => $accounts,
            ], ['Accept' => 'application/json'])->assertOk()
                ->assertJsonPath('data.extracted.counterparty', 'ACME CUSTOMER SDN BHD')
                ->assertJsonPath('data.extracted.buyer_name', null)
                ->assertJsonPath('data.extracted.issuer_name', 'Example Bank Berhad')
                ->assertJsonPath('data.extracted.total', 100.00)
                ->assertJsonPath('data.extracted.reference_number', '2601010000000001')
                ->assertJsonPath('data.extracted.transaction_date', '2026-10-15')
                ->assertJsonPath('data.extracted.source_document_kind', 'receipt')
                ->assertJsonPath('data.classification.document_kind', 'receipt')
                ->assertJsonPath('data.classification.voucher_type', 'receipt_voucher')
                ->assertJsonPath('data.journal_entry.lines.0.account_code', 'BS/CA/CNB/BANK/10000')
                ->assertJsonPath('data.journal_entry.lines.1.account_code', 'BS/CA/TRV/TRDB/10000')
                ->assertJsonPath('data.journal_entry.lines.1.account_name', 'ACME CUSTOMER SDN BHD')
                ->assertJsonPath('data.journal_entry.lines.1.recommendation', null)
                ->assertJsonPath('validation.requires_manual_review', false)
                ->assertJsonPath('validation.is_postable', false)
                ->assertJsonPath('validation.errors', []);
            $this->assertSame(['posting_date_required'], array_column($response->json('validation.warnings'), 'code'));
            $results[] = $response->json();
        }
        $this->assertSame($results[0], $results[1]);
    }

    private function payload(): array
    {
        return [
            'transaction_count' => 1, 'document_kind' => 'invoice', 'routing_document_kind' => 'supplier_bill', 'document_direction' => 'incoming',
            'transaction_date' => '2026-09-21', 'invoice_date' => '2026-09-21',
            'reference_number' => 'INV-001', 'counterparty' => 'Renovation Contractor', 'description' => 'Restaurant renovation costs',
            'currency' => 'MYR', 'subtotal' => 100, 'tax' => 0, 'total' => 100,
            'trade_nature' => 'non_trade', 'economic_event' => 'Renovation purchase',
            'treatment_summary' => 'Proposed renovation asset and non-trade liability', 'overall_confidence' => 0.9,
            'payment_status' => 'unknown', 'payment_evidence_kind' => 'payment_terms', 'payment_status_evidence' => '7 days',
            'lines' => [
                ['selected_account_code' => 'BS/NA/PPE/RENO/10000', 'description' => 'Restaurant renovation costs', 'debit' => 100, 'credit' => 0, 'confidence' => 0.9, 'reason' => 'Proposed capitalisation', 'evidence' => 'Renovation RM100', 'required_role' => 'ppe', 'required_account_type' => 'asset', 'required_prefix' => 'BS/NA/PPE/RENO', 'requires_control_account' => false, 'required_control_role' => null, 'account_error' => null],
                ['selected_account_code' => null, 'suggested_account_name' => 'OTHER CREDITORS - Renovation Contractor', 'description' => 'Amount payable for renovation', 'debit' => 0, 'credit' => 100, 'confidence' => 1.0, 'reason' => 'Non-trade creditor required', 'evidence' => 'Invoice issued by Renovation Contractor', 'required_role' => 'other_payable', 'required_account_type' => 'liability', 'required_prefix' => 'BS/CL/OPY/OPCR', 'requires_control_account' => false, 'required_control_role' => null, 'account_error' => 'ACCOUNT_NOT_AVAILABLE'],
            ],
        ];
    }

    private function request(array $payload, ?array $company = null): TestResponse
    {
        $this->app->bind(AccountingOcrClient::class, fn () => new class($payload) implements AccountingOcrClient
        {
            public function __construct(private readonly array $payload) {}

            public function extract(string $documentContent, string $mimeType, array $accounts, ?string $companyContext = null, array $context = []): array
            {
                return $this->payload;
            }
        });
        $document = UploadedFile::fake()->createWithContent('invoice.pdf', "%PDF-1.4\nTest invoice");

        return $this->post('/api/ocr/accounting', [
            'document' => $document, 'posting_date' => '2026-09-21',
            'company' => $company,
            'accounts' => [['code' => 'BS/NA/PPE/RENO/10000', 'name' => 'RENOVATION', 'type' => 'asset', 'subtype' => 'other']],
        ], ['Accept' => 'application/json']);
    }

    public function test_printed_parties_determine_the_perspective_for_a_matching_company(): void
    {
        $payload = $this->payload();
        $payload['issuer_name'] = 'Hap Seng Plantations (River Estates) Sdn Bhd';
        $payload['buyer_name'] = 'YEE SHUN FOOD SUPPLIES SDN BHD';
        $payload['document_direction'] = 'outgoing';
        $payload['counterparty'] = 'YEE SHUN FOOD SUPPLIES SDN BHD';

        $buyerResponse = $this->request($payload, ['name' => 'Yee Shun Food Supplies Sdn. Bhd.'])->assertOk()
            ->assertJsonPath('data.extracted.counterparty', 'Hap Seng Plantations (River Estates) Sdn Bhd')
            ->assertJsonPath('data.classification.document_kind', 'supplier_bill')
            ->assertJsonPath('validation.is_postable', false);
        $this->assertContains('document_perspective_conflict', array_column($buyerResponse->json('validation.errors'), 'code'));

        $payload['document_direction'] = 'incoming';
        $issuerResponse = $this->request($payload, ['name' => 'Hap Seng Plantations River Estates Sdn Bhd'])->assertOk()
            ->assertJsonPath('data.extracted.counterparty', 'YEE SHUN FOOD SUPPLIES SDN BHD')
            ->assertJsonPath('data.classification.document_kind', 'unknown');
        $this->assertContains('document_perspective_conflict', array_column($issuerResponse->json('validation.errors'), 'code'));

        $payload['document_direction'] = 'incoming';
        $matchingResponse = $this->request($payload, ['name' => 'YEE SHUN FOOD SUPPLIES SDN BHD'])->assertOk()
            ->assertJsonPath('data.extracted.counterparty', 'Hap Seng Plantations (River Estates) Sdn Bhd');
        $this->assertNotContains('document_perspective_conflict', array_column($matchingResponse->json('validation.errors'), 'code'));
    }

    public function test_it_returns_only_the_compact_public_contract(): void
    {
        $response = $this->request($this->payload());
        $response->assertOk()->assertJsonPath('data.journal_entry.lines.0.debit', '100.00')
            ->assertJsonPath('data.journal_entry.lines.0.credit', '0.00')
            ->assertJsonPath('data.journal_entry.lines.1.account_code', null)
            ->assertJsonPath('data.journal_entry.lines.1.account_name', 'Renovation Contractor')
            ->assertJsonPath('data.journal_entry.lines.1.recommendation', [
                'parent_definition_key' => 'OPCR', 'parent_code' => 'BS/CL/OPY/OPCR', 'account_type' => 'liability', 'account_subtype' => 'other_payable',
            ])
            ->assertJsonPath('validation.is_postable', false)
            ->assertJsonPath('validation.errors.0.field', 'journal_entry.lines[1]')
            ->assertJsonPath('validation.status', 'review_required')
            ->assertJsonPath('validation.errors.0.message', 'No supplied account satisfies the required accounting classification and control role. Arkcloudant must resolve this requirement before posting.');
        $body = $response->json();
        $this->assertSame(['data', 'validation'], array_keys($body));
        $this->assertSame(['extracted', 'classification', 'journal_entry'], array_keys($body['data']));
        $this->assertSame(['transaction_date', 'original_document_reference', 'source_document_kind', 'reference_number', 'issuer_name', 'buyer_name', 'counterparty', 'description', 'currency', 'total'], array_keys($body['data']['extracted']));
        $this->assertSame(['voucher_type', 'document_kind'], array_keys($body['data']['classification']));
        $this->assertSame('general_voucher', $body['data']['classification']['voucher_type']);
        $this->assertSame('supplier_bill', $body['data']['classification']['document_kind']);
        $this->assertSame(['lines'], array_keys($body['data']['journal_entry']));
        $this->assertSame(['account_code', 'account_name', 'description', 'debit', 'credit', 'confidence', 'reason', 'evidence', 'recommendation'], array_keys($body['data']['journal_entry']['lines'][0]));
        $this->assertSame(['status', 'is_postable', 'requires_manual_review', 'warnings', 'errors'], array_keys($body['validation']));
        $this->assertNotEmpty($body['validation']['warnings']);
        $this->assertContains('capitalisation', array_column($body['validation']['warnings'], 'field'));
    }

    public function test_uncertain_or_invalid_routing_kind_falls_back_to_unknown(): void
    {
        $payload = $this->payload();
        $payload['routing_document_kind'] = 'credit_note';
        $this->request($payload)->assertOk()->assertJsonPath('data.classification.document_kind', 'unknown');

        $payload['routing_document_kind'] = 'supplier_payment';
        $this->request($payload)->assertOk()->assertJsonPath('data.classification.document_kind', 'unknown');

        $payload['routing_document_kind'] = 'supplier_bill';
        $payload['payment_status'] = 'paid';
        $payload['payment_evidence_kind'] = 'settlement_confirmation';
        $payload['payment_status_evidence'] = 'Payment confirmed';
        $this->request($payload)->assertOk()->assertJsonPath('data.classification.document_kind', 'unknown');

        $payload['routing_document_kind'] = 'supplier_payment';
        $this->request($payload)->assertOk()->assertJsonPath('data.classification.document_kind', 'supplier_payment');
    }

    public function test_incoming_credit_note_routes_separately_from_supplier_bills(): void
    {
        $payload = $this->payload();
        $payload['document_kind'] = 'credit_note';
        $payload['routing_document_kind'] = 'supplier_bill';
        $payload['lines'][1] = [
            'selected_account_code' => null, 'suggested_account_name' => 'Cash at Bank',
            'description' => 'Supplier refund', 'debit' => 0, 'credit' => 100,
            'confidence' => 0.9, 'reason' => 'Refund proposal', 'evidence' => 'Credit note RM100',
            'required_role' => 'bank', 'required_account_type' => 'asset',
            'required_prefix' => 'BS/CA/CNB/BANK', 'requires_control_account' => false,
            'required_control_role' => null, 'account_error' => 'ACCOUNT_NOT_AVAILABLE',
        ];

        $this->request($payload)->assertOk()
            ->assertJsonPath('data.classification.document_kind', 'supplier_credit_note')
            ->assertJsonPath('data.classification.voucher_type', 'general_voucher');

        $payload['document_direction'] = 'outgoing';
        $this->request($payload)->assertOk()
            ->assertJsonPath('data.classification.document_kind', 'unknown');
    }

    public function test_error_indices_follow_grouped_public_lines_and_all_evidence_is_retained(): void
    {
        $payload = $this->payload();
        $first = $payload['lines'][0];
        $first['debit'] = 60;
        $second = $first;
        $second['debit'] = 40;
        $second['confidence'] = 0.8;
        $second['reason'] = 'Second improvement cost';
        $second['evidence'] = 'Second item RM40';
        $payload['lines'] = [$first, $second, $payload['lines'][1]];
        $response = $this->request($payload);
        $response->assertOk()->assertJsonCount(2, 'data.journal_entry.lines')
            ->assertJsonPath('data.journal_entry.lines.0.debit', '100.00')
            ->assertJsonPath('data.journal_entry.lines.0.confidence', 0.8)
            ->assertJsonPath('validation.errors.0.field', 'journal_entry.lines[1]');
        $this->assertStringContainsString('Second item RM40', $response->json('data.journal_entry.lines.0.evidence'));
        $this->assertStringNotContainsString('audit_support', $response->json('data.journal_entry.lines.0.reason'));
    }

    public function test_readable_credit_note_without_a_journal_proposal_requires_review(): void
    {
        $payload = $this->payload();
        $payload['transaction_count'] = 0;
        $payload['document_kind'] = 'credit_note';
        $payload['reference_number'] = '999900397';
        $payload['total'] = 20;
        $payload['lines'] = [];

        $this->request($payload)->assertOk()
            ->assertJsonPath('data.extracted.reference_number', '999900397')
            ->assertJsonPath('data.classification.document_kind', 'supplier_credit_note')
            ->assertJsonPath('data.classification.voucher_type', 'general_voucher')
            ->assertJsonCount(0, 'data.journal_entry.lines')
            ->assertJsonPath('validation.status', 'review_required')
            ->assertJsonPath('validation.is_postable', false)
            ->assertJsonPath('validation.errors.0.code', 'journal_proposal_missing');
    }

    public function test_credit_note_preserves_printed_facts_when_demo_company_matches_neither_party(): void
    {
        $payload = $this->payload();
        $payload['document_kind'] = 'credit_note';
        $payload['routing_document_kind'] = 'unknown';
        $payload['document_direction'] = 'unknown';
        $payload['transaction_date'] = '2026-03-31';
        $payload['reference_number'] = '999900397';
        $payload['issuer_name'] = 'Hap Seng Plantations (River Estates) Sdn Bhd';
        $payload['buyer_name'] = 'YEE SHUN FOOD SUPPLIES SDN BHD';
        $payload['counterparty'] = 'YEE SHUN FOOD SUPPLIES SDN BHD';
        $payload['description'] = 'Reversal Stamp Duty Certificate Fee';
        $payload['original_document_reference'] = '999900303';
        $payload['total'] = 20;
        $payload['lines'] = [];

        $response = $this->request($payload, ['name' => 'Template Demo Sdn Bhd'])->assertOk()
            ->assertJsonPath('data.extracted.source_document_kind', 'credit_note')
            ->assertJsonPath('data.extracted.issuer_name', 'Hap Seng Plantations (River Estates) Sdn Bhd')
            ->assertJsonPath('data.extracted.buyer_name', 'YEE SHUN FOOD SUPPLIES SDN BHD')
            ->assertJsonPath('data.extracted.counterparty', null)
            ->assertJsonPath('data.extracted.total', 20.0)
            ->assertJsonPath('data.extracted.original_document_reference', '999900303')
            ->assertJsonPath('data.classification.document_kind', 'unknown')
            ->assertJsonCount(0, 'data.journal_entry.lines')
            ->assertJsonPath('validation.is_postable', false);

        $this->assertContains('document_party_mismatch', array_column($response->json('validation.warnings'), 'code'));
    }

    public function test_buyer_side_credit_note_proposal_routes_but_cannot_post_without_a_confirmed_perspective(): void
    {
        $payload = $this->payload();
        $payload['document_kind'] = 'credit_note';
        $payload['routing_document_kind'] = 'unknown';
        $payload['document_direction'] = 'unknown';
        $payload['issuer_name'] = 'Hap Seng Plantations (River Estates) Sdn Bhd';
        $payload['buyer_name'] = 'YEE SHUN FOOD SUPPLIES SDN BHD';
        $payload['total'] = 20;
        $payload['lines'] = [
            array_replace($payload['lines'][1], [
                'debit' => 20, 'credit' => 0, 'required_role' => 'other_payable',
            ]),
            array_replace($payload['lines'][0], [
                'selected_account_code' => null, 'debit' => 0, 'credit' => 20,
                'required_role' => 'expense', 'required_account_type' => 'expense',
                'required_prefix' => 'PL/OE/OEX/GNEX',
            ]),
        ];

        $response = $this->request($payload)->assertOk()
            ->assertJsonPath('data.classification.document_kind', 'supplier_credit_note')
            ->assertJsonPath('validation.is_postable', false);

        $this->assertContains('document_perspective_unclear', array_column($response->json('validation.errors'), 'code'));
    }

    public function test_it_retries_a_readable_credit_note_without_lines_for_a_provisional_buyer_side_proposal(): void
    {
        $first = $this->payload();
        $first['document_kind'] = 'credit_note';
        $first['routing_document_kind'] = 'unknown';
        $first['document_direction'] = 'unknown';
        $first['reference_number'] = '999900397';
        $first['issuer_name'] = 'Hap Seng Plantations (River Estates) Sdn Bhd';
        $first['buyer_name'] = 'YEE SHUN FOOD SUPPLIES SDN BHD';
        $first['total'] = 20;
        $first['lines'] = [];
        $second = $first;
        $second['lines'] = [
            array_replace($this->payload()['lines'][1], ['debit' => 20, 'credit' => 0]),
            array_replace($this->payload()['lines'][0], [
                'selected_account_code' => null, 'debit' => 0, 'credit' => 20,
                'required_role' => 'expense', 'required_account_type' => 'expense',
                'required_prefix' => 'PL/OE/OEX/GNEX',
            ]),
        ];

        $client = new class($first, $second) implements AccountingOcrClient
        {
            public int $calls = 0;

            public function __construct(private readonly array $first, private readonly array $second) {}

            public function extract(string $documentContent, string $mimeType, array $accounts, ?string $companyContext = null, array $context = []): array
            {
                $this->calls++;

                return ($context['proposal_perspective'] ?? null) === 'buyer' ? $this->second : $this->first;
            }
        };
        $this->app->instance(AccountingOcrClient::class, $client);

        $response = $this->post('/api/ocr/accounting', [
            'document' => UploadedFile::fake()->createWithContent('credit-note.pdf', "%PDF-1.4\nTest credit note"),
            'posting_date' => '2026-03-31',
            'accounts' => [['code' => 'BS/NA/PPE/RENO/10000', 'name' => 'RENOVATION', 'type' => 'asset', 'subtype' => 'other']],
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('data.classification.document_kind', 'supplier_credit_note')
            ->assertJsonCount(2, 'data.journal_entry.lines')
            ->assertJsonPath('validation.is_postable', false);
        $this->assertSame(2, $client->calls);
        $this->assertContains('document_perspective_unclear', array_column($response->json('validation.errors'), 'code'));
    }

    public function test_missing_customer_ledger_names_the_customer_instead_of_trade_debtors(): void
    {
        $payload = $this->payload();
        $payload['document_kind'] = 'credit_note';
        $payload['document_direction'] = 'outgoing';
        $payload['counterparty'] = 'SHINY ALUMINIUM GLASS SDN BHD';
        $payload['lines'] = [
            ['selected_account_code' => null, 'suggested_account_name' => 'TRADE DEBTORS', 'description' => 'Credit note reduces customer balance', 'debit' => 0, 'credit' => 100, 'confidence' => 0.9, 'reason' => 'Reverse receivable', 'evidence' => 'Customer: SHINY ALUMINIUM GLASS SDN BHD', 'required_role' => 'trade_receivable', 'required_account_type' => 'asset', 'required_prefix' => 'BS/CA/TRV/TRDB', 'requires_control_account' => false, 'required_control_role' => null, 'account_error' => 'ACCOUNT_NOT_AVAILABLE'],
            ['selected_account_code' => null, 'suggested_account_name' => 'Sales Income', 'description' => 'Reverse sales', 'debit' => 100, 'credit' => 0, 'confidence' => 0.9, 'reason' => 'Reverse revenue', 'evidence' => 'Credit note total RM100', 'required_role' => 'revenue', 'required_account_type' => 'revenue', 'required_prefix' => 'PL/TI/TIN/SLIC', 'requires_control_account' => false, 'required_control_role' => null, 'account_error' => 'ACCOUNT_NOT_AVAILABLE'],
        ];

        $this->request($payload)->assertOk()
            ->assertJsonPath('data.journal_entry.lines.0.account_code', null)
            ->assertJsonPath('data.journal_entry.lines.0.account_name', 'SHINY ALUMINIUM GLASS SDN BHD')
            ->assertJsonPath('data.journal_entry.lines.0.recommendation.parent_code', 'BS/CA/TRV/TRDB')
            ->assertJsonPath('validation.is_postable', false);
    }

    public function test_a_document_without_readable_transaction_fields_stays_unreadable(): void
    {
        $payload = $this->payload();
        $payload['transaction_count'] = 0;
        $payload['document_kind'] = 'unknown';
        $payload['reference_number'] = null;
        $payload['total'] = null;
        $payload['lines'] = [];

        $this->request($payload)->assertStatus(422)
            ->assertJsonPath('error_code', 'accounting_document_unreadable');
    }
}
