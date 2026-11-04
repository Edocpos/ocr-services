<?php

namespace Tests\Feature;

use App\Contracts\Ocr\StructuredAccountingClient;
use App\Http\Requests\RecommendAccountingRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StructuredAccountingRecommendApiTest extends TestCase
{
    private function requestFor(string $kind): array
    {
        $commercial = in_array($kind, ['customer_invoice', 'customer_credit_note', 'customer_debit_note', 'supplier_bill', 'supplier_credit_note', 'supplier_debit_note'], true);
        $date = match ($kind) {
            'customer_receipt' => 'receipt_date', 'supplier_payment' => 'payment_date',
            'customer_refund', 'supplier_refund' => 'refund_date', default => 'document_date',
        };
        $document = [$date => '2026-11-04', 'reference' => 'REF-1'];
        if ($commercial) {
            $document += [
                'subtotal' => '100.00', 'tax' => '0.00', 'total' => '100.00',
                'lines' => [['line_no' => 1, 'description' => 'Service', 'quantity' => '1', 'unit_price' => '100.00', 'amount' => '100.00']],
            ];
        } else {
            $document['amount'] = '100.00';
            if (in_array($kind, ['customer_receipt', 'supplier_payment'], true)) {
                $document['allocations'] = [];
                $document['unapplied_amount'] = '100.00';
            }
        }

        return [
            'document_kind' => $kind, 'workflow_context' => $kind, 'currency' => 'MYR',
            'counterparty' => ['role' => str_starts_with($kind, 'customer_') ? 'customer' : 'supplier', 'name' => 'Acme'],
            'document' => $document,
            'accounts' => [
                ['code' => 'BS/CA/CNB/BANK/10000', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'bank'],
                ['code' => 'PL/TI/TIN/SVIC/10000', 'name' => 'Service Income', 'type' => 'revenue', 'subtype' => 'other'],
            ],
        ];
    }

    private function bindProposal(?array $lines = null): void
    {
        $lines ??= [
            ['account_code' => 'BS/CA/CNB/BANK/10000', 'required_account_type' => 'asset', 'required_role' => 'bank', 'debit' => 100, 'credit' => 0, 'confidence' => .95],
            ['account_code' => 'PL/TI/TIN/SVIC/10000', 'required_account_type' => 'revenue', 'required_role' => 'revenue', 'debit' => 0, 'credit' => 100, 'confidence' => .95],
        ];
        $this->app->bind(StructuredAccountingClient::class, fn () => new class($lines) implements StructuredAccountingClient
        {
            public function __construct(private array $lines) {}

            public function recommend(array $request): array
            {
                return ['lines' => $this->lines];
            }
        });
    }

    public function test_all_ten_document_kinds_return_the_structured_contract(): void
    {
        $this->bindProposal();
        foreach (RecommendAccountingRequest::KINDS as $kind) {
            $response = $this->postJson('/api/accounting/recommend', $this->requestFor($kind));
            $response->assertOk()->assertJsonPath('data.classification.document_kind', $kind)
                ->assertJsonPath('data.journal_entry.total_debit', 100)
                ->assertJsonPath('data.journal_entry.is_balanced', true)
                ->assertJsonPath('validation.is_postable', true)
                ->assertJsonPath('meta.input_mode', 'structured');
            $this->assertArrayNotHasKey('account_id', $response->json('data.journal_entry.lines.0'));
        }
    }

    public function test_it_rejects_empty_accounts_and_inconsistent_totals(): void
    {
        $request = $this->requestFor('customer_invoice');
        $request['accounts'] = [];
        $request['document']['total'] = '101.00';
        $this->postJson('/api/accounting/recommend', $request)->assertUnprocessable()
            ->assertJsonValidationErrors(['accounts', 'document.total']);
    }

    public function test_it_rejects_provider_accounts_outside_the_supplied_chart(): void
    {
        $this->bindProposal([['account_code' => 'MADE-UP', 'required_account_type' => 'asset', 'required_role' => 'bank', 'debit' => 100, 'credit' => 0]]);
        $this->postJson('/api/accounting/recommend', $this->requestFor('customer_invoice'))
            ->assertStatus(502)->assertJsonPath('error_code', 'accounting_proposal_invalid');
    }

    public function test_it_rejects_provider_amounts_that_do_not_reconcile(): void
    {
        $this->bindProposal([
            ['account_code' => 'BS/CA/CNB/BANK/10000', 'required_account_type' => 'asset', 'required_role' => 'bank', 'debit' => 90, 'credit' => 0],
            ['account_code' => 'PL/TI/TIN/SVIC/10000', 'required_account_type' => 'revenue', 'required_role' => 'revenue', 'debit' => 0, 'credit' => 90],
        ]);
        $this->postJson('/api/accounting/recommend', $this->requestFor('customer_invoice'))->assertStatus(502)
            ->assertJsonPath('error_code', 'accounting_proposal_invalid');
    }

    public function test_unmatched_account_returns_recommendation_and_blocks_postability(): void
    {
        $this->bindProposal([
            ['account_code' => 'BS/CA/CNB/BANK/10000', 'required_account_type' => 'asset', 'required_role' => 'bank', 'debit' => 100, 'credit' => 0, 'confidence' => .95],
            [
                'account_code' => null, 'required_account_type' => 'revenue', 'required_role' => 'revenue',
                'debit' => 0, 'credit' => 100, 'confidence' => .9,
                'recommendation' => ['suggested_name' => 'Advisory Revenue', 'parent_code' => 'PL/TI/TIN/SVIC', 'account_type' => 'revenue', 'account_subtype' => 'other'],
            ],
        ]);
        $this->postJson('/api/accounting/recommend', $this->requestFor('customer_invoice'))
            ->assertOk()->assertJsonPath('data.journal_entry.lines.1.account_code', null)
            ->assertJsonPath('data.journal_entry.lines.1.recommendation.suggested_name', 'Advisory Revenue')
            ->assertJsonPath('validation.is_postable', false);
    }

    public function test_receipt_accepts_empty_allocations_without_an_unapplied_field(): void
    {
        $this->bindProposal();
        $request = $this->requestFor('customer_receipt');
        unset($request['document']['unapplied_amount']);
        $this->postJson('/api/accounting/recommend', $request)->assertOk();
    }

    public function test_it_rejects_another_customers_named_ledger(): void
    {
        $this->bindProposal([
            ['account_code' => 'BS/CA/TRV/TRDB/10000', 'required_account_type' => 'asset', 'required_role' => 'trade_receivable', 'debit' => 100, 'credit' => 0, 'confidence' => .95],
            ['account_code' => 'PL/TI/TIN/SVIC/10000', 'required_account_type' => 'revenue', 'required_role' => 'revenue', 'debit' => 0, 'credit' => 100, 'confidence' => .95],
        ]);
        $request = $this->requestFor('customer_invoice');
        $request['accounts'][] = ['code' => 'BS/CA/TRV/TRDB/10000', 'name' => 'Different Customer', 'type' => 'asset', 'subtype' => 'trade_receivable'];
        $this->postJson('/api/accounting/recommend', $request)->assertStatus(502)
            ->assertJsonPath('error_code', 'accounting_proposal_invalid');
    }

    public function test_it_distinguishes_provider_rejection_from_an_invalid_proposal(): void
    {
        $this->app->bind(StructuredAccountingClient::class, fn () => new class implements StructuredAccountingClient
        {
            public function recommend(array $request): array
            {
                throw new \RuntimeException('ocr_provider_request_rejected');
            }
        });
        $this->postJson('/api/accounting/recommend', $this->requestFor('customer_invoice'))
            ->assertStatus(502)->assertJsonPath('error_code', 'ocr_provider_request_rejected')
            ->assertJsonStructure(['request_id']);
    }

    public function test_gemini_http_400_is_reported_as_provider_rejection(): void
    {
        config()->set('ocr.gemini_api_key', 'test-key');
        Http::fake(['*' => Http::response(['error' => ['message' => 'Bad request']], 400)]);
        $this->postJson('/api/accounting/recommend', $this->requestFor('customer_invoice'))
            ->assertStatus(502)->assertJsonPath('error_code', 'ocr_provider_request_rejected');
        Http::assertSent(fn ($request) => isset($request['generationConfig']['responseSchema']));
    }
}
