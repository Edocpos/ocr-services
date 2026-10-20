<?php

namespace Tests\Feature;

use App\Contracts\Ocr\AccountingOcrClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AccountingOcrApiTest extends TestCase
{
    private function payload(): array
    {
        return [
            'transaction_count' => 1, 'document_kind' => 'invoice', 'document_direction' => 'incoming',
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

    private function request(array $payload): TestResponse
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
            'accounts' => [['code' => 'BS/NA/PPE/RENO/10000', 'name' => 'RENOVATION', 'type' => 'asset', 'subtype' => 'other']],
        ], ['Accept' => 'application/json']);
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
        $this->assertSame(['transaction_date', 'reference_number', 'counterparty', 'description', 'currency'], array_keys($body['data']['extracted']));
        $this->assertSame(['voucher_type'], array_keys($body['data']['classification']));
        $this->assertSame(['lines'], array_keys($body['data']['journal_entry']));
        $this->assertSame(['account_code', 'account_name', 'description', 'debit', 'credit', 'confidence', 'reason', 'evidence', 'recommendation'], array_keys($body['data']['journal_entry']['lines'][0]));
        $this->assertSame(['status', 'is_postable', 'requires_manual_review', 'warnings', 'errors'], array_keys($body['validation']));
        $this->assertNotEmpty($body['validation']['warnings']);
        $this->assertContains('capitalisation', array_column($body['validation']['warnings'], 'field'));
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
            ->assertJsonCount(0, 'data.journal_entry.lines')
            ->assertJsonPath('validation.status', 'review_required')
            ->assertJsonPath('validation.is_postable', false)
            ->assertJsonPath('validation.errors.0.code', 'journal_proposal_missing');
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
