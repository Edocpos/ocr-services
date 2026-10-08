<?php

namespace Tests\Feature;

use App\Contracts\Ocr\AccountingOcrClient;
use App\Services\AccountingOcr\AccountCodeRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AccountingOcrApiTest extends TestCase
{
    private function fakeDocument(): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+Z2ioAAAAASUVORK5CYII=');

        return UploadedFile::fake()->createWithContent('voucher.png', $png ?: 'x');
    }

    /** @param array<string, mixed> $payload */
    private function bindPayload(array $payload): void
    {
        $this->app->bind(AccountingOcrClient::class, fn () => new class($payload) implements AccountingOcrClient
        {
            public function __construct(private readonly array $payload) {}

            public function extract(string $documentContent, string $mimeType, array $accounts, ?string $companyContext = null, array $context = []): array
            {
                $payload = $this->payload;
                if ($companyContext === '{"name":"ACME SDN BHD","role":"invoice issuer"}') {
                    $payload['document_direction'] = 'outgoing';
                }

                $registry = new AccountCodeRegistry;
                foreach ($payload['lines'] as &$line) {
                    $prefix = $line['required_prefix'] ?? $registry->prefixForAccount($line['selected_account_code'] ?? '');
                    $guidance = $registry->guidance($prefix);
                    $line['required_prefix'] = $prefix;
                    $line['required_role'] ??= $guidance['role'] ?? 'expense';
                    $line['required_account_type'] ??= $guidance['type'] ?? 'expense';
                    $line['requires_control_account'] ??= in_array($line['required_role'], ['trade_payable', 'trade_receivable'], true);
                    $line['required_control_role'] ??= match ($line['required_role']) {
                        'trade_payable' => 'ap', 'trade_receivable' => 'ar', default => null
                    };
                    $line['account_error'] ??= $line['selected_account_code'] === null ? 'ACCOUNT_NOT_AVAILABLE' : null;
                }
                unset($line);

                return $payload;
            }
        });
    }

    /** @return list<array<string, mixed>> */
    private function accounts(): array
    {
        return [
            ['code' => 'BS/CA/CNB/BANK/10000', 'name' => 'Maybank', 'type' => 'asset', 'subtype' => 'bank', 'aliases' => []],
            ['code' => 'BS/CA/CNB/CASH/10000', 'name' => 'Cash on Hand', 'type' => 'asset', 'subtype' => 'cash', 'aliases' => []],
            ['code' => 'PL/OE/OEX/OPEX/10001', 'name' => 'Office Expenses', 'type' => 'expense', 'subtype' => 'other', 'aliases' => []],
            ['code' => 'BS/CL/TPY/TPTC/10000', 'name' => 'Trade Payables', 'type' => 'liability', 'subtype' => 'accounts_payable', 'aliases' => [], 'is_control_account' => true, 'control_role' => 'ap'],
            ['code' => 'PL/OI/RIN/SLIC/10000', 'name' => 'Sales Income', 'type' => 'revenue', 'subtype' => 'other', 'aliases' => []],
        ];
    }

    public function test_it_returns_a_postable_payment_voucher_using_submitted_accounts(): void
    {
        $this->bindPayload($this->payload([
            $this->line('PL/OE/OEX/OPEX/10001', 100, 0, 'Office supplies', 'Invoice: office supplies'),
            $this->line('BS/CA/CNB/BANK/10000', 0, 100, 'Paid from bank', 'Paid via Maybank'),
        ]));

        $response = $this->post('/api/ocr/accounting', [
            'document' => $this->fakeDocument(),
            'accounts' => json_encode($this->accounts()),
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('data.classification.voucher_type', 'payment_voucher')
            ->assertJsonPath('data.journal_entry.is_balanced', true)
            ->assertJsonPath('data.journal_entry.lines.0.account_code', 'PL/OE/OEX/OPEX/10001')
            ->assertJsonPath('data.journal_entry.lines.0.account_name', 'Office Expenses')
            ->assertJsonPath('data.journal_entry.lines.0.match_status', 'matched')
            ->assertJsonPath('data.journal_entry.lines.0.recommendation', null)
            ->assertJsonPath('validation.is_postable', true)
            ->assertJsonPath('validation.requires_manual_review', false)
            ->assertJsonPath('meta.provider', 'gemini');
    }

    public function test_it_passes_a_json_company_snapshot_without_requiring_a_schema(): void
    {
        $this->bindPayload($this->payload([
            $this->line('BS/CA/CNB/BANK/10000', 100, 0, 'Customer paid us', 'Paid RM100'),
            $this->line('PL/OI/RIN/SLIC/10000', 0, 100, 'Sale to customer', 'Invoice total RM100'),
        ]));

        $this->post('/api/ocr/accounting', [
            'document' => $this->fakeDocument(),
            'company' => '{"name":"ACME SDN BHD","role":"invoice issuer"}',
            'accounts' => json_encode($this->accounts()),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.extracted.document_direction', 'outgoing')
            ->assertJsonPath('data.classification.voucher_type', 'receipt_voucher');
    }

    public function test_it_returns_a_general_voucher_for_a_credit_supplier_invoice(): void
    {
        $this->bindPayload($this->payload([
            $this->line('PL/OE/OEX/OPEX/10001', 100, 0, 'Expense incurred', 'Supplier invoice'),
            $this->line('BS/CL/TPY/TPTC/10000', 0, 100, 'Amount owed to supplier', 'Payment terms: 30 days'),
        ], ['payment_method' => null, 'payment_reference' => null]));

        $this->post('/api/ocr/accounting', [
            'document' => $this->fakeDocument(),
            'accounts' => json_encode($this->accounts()),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.classification.voucher_type', 'general_voucher')
            ->assertJsonPath('validation.is_postable', true);
    }

    public function test_it_reports_the_required_output_tax_account_when_unavailable(): void
    {
        $tax = $this->line(null, 0, 6, 'Explicit SST is payable', 'SST 6.00');
        $tax['required_prefix'] = 'BS/CL/CTL/SNTP';

        $this->bindPayload($this->payload([
            $this->line('BS/CA/CNB/BANK/10000', 106, 0, 'Money received', 'Total paid RM106'),
            $this->line('PL/OI/RIN/SLIC/10000', 0, 100, 'Sales revenue', 'Subtotal RM100'),
            $tax,
        ], ['subtotal' => 100, 'tax' => 6, 'total' => 106]));

        $this->post('/api/ocr/accounting', [
            'document' => $this->fakeDocument(),
            'accounts' => json_encode($this->accounts()),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.classification.voucher_type', 'receipt_voucher')
            ->assertJsonPath('data.journal_entry.lines.2.account_code', null)
            ->assertJsonPath('data.journal_entry.lines.2.account_name', null)
            ->assertJsonPath('data.journal_entry.lines.2.required_account.parent_code', 'BS/CL/CTL/SNTP')
            ->assertJsonPath('data.journal_entry.lines.2.required_account.account_subtype', 'output_tax')
            ->assertJsonMissing(['code' => 'tax_account_missing']);
    }

    public function test_it_recommends_a_missing_account_without_creating_or_posting_it(): void
    {
        $accounts = $this->accounts();
        $accounts[] = ['code' => 'PL/OE/OEX/OPEX/10003', 'name' => 'Utilities', 'type' => 'expense', 'subtype' => 'other'];
        $missing = $this->line(null, 100, 0, 'No submitted account is suitable', 'Annual cloud software subscription');
        $missing['suggested_account_name'] = 'Cloud Software Subscription';
        $missing['required_account_type'] = 'expense';
        $missing['required_prefix'] = 'PL/OE/OEX/OPEX';

        $this->bindPayload($this->payload([
            $missing,
            $this->line('BS/CA/CNB/BANK/10000', 0, 100, 'Paid from bank', 'Bank payment'),
        ]));

        $this->post('/api/ocr/accounting', [
            'document' => $this->fakeDocument(),
            'accounts' => json_encode($accounts),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.journal_entry.lines.0.account_code', null)
            ->assertJsonPath('data.journal_entry.lines.0.account_name', null)
            ->assertJsonPath('data.journal_entry.lines.0.match_status', 'account_not_available')
            ->assertJsonPath('data.journal_entry.lines.0.required_account.account_type', 'expense')
            ->assertJsonPath('data.journal_entry.lines.0.required_account.parent_code', 'PL/TE/TEX/OPEX')
            ->assertJsonMissingPath('data.journal_entry.lines.0.recommendation.provisional_code')
            ->assertJsonPath('data.journal_entry.lines.0.recommendation.suggested_name', 'Cloud Software Subscription')
            ->assertJsonPath('validation.is_postable', false)
            ->assertJsonPath('validation.requires_manual_review', true);
    }

    public function test_it_keeps_missing_account_lines_incomplete(): void
    {
        $first = $this->line(null, 60, 0, 'First missing expense', 'Software RM60');
        $first['required_prefix'] = 'PL/OE/OEX/OPEX';
        $second = $this->line(null, 40, 0, 'Second missing expense', 'Parking RM40');
        $second['required_prefix'] = 'PL/OE/OEX/OPEX';

        $this->bindPayload($this->payload([
            $first,
            $second,
            $this->line('BS/CA/CNB/BANK/10000', 0, 100, 'Paid from bank', 'Bank payment'),
        ]));

        $this->post('/api/ocr/accounting', [
            'document' => $this->fakeDocument(),
            'accounts' => json_encode($this->accounts()),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.journal_entry.lines.0.account_name', null)
            ->assertJsonPath('data.journal_entry.lines.1.account_name', null)
            ->assertJsonPath('data.journal_entry.lines.0.required_account.parent_code', 'PL/TE/TEX/OPEX')
            ->assertJsonPath('data.journal_entry.lines.1.required_account.parent_code', 'PL/TE/TEX/OPEX')
            ->assertJsonPath('data.journal_entry.lines.0.recommendation.parent_code', 'PL/TE/TEX/OPEX')
            ->assertJsonPath('data.journal_entry.lines.1.recommendation.parent_code', 'PL/TE/TEX/OPEX');
    }

    public function test_it_reports_a_missing_ar_control_without_inventing_a_customer_account(): void
    {
        $receivable = $this->line(null, 100, 0, 'Customer owes the invoice total', 'Bill to: Northwind Sdn Bhd');
        $receivable['required_prefix'] = 'BS/CA/TRV/TRDB';

        $this->bindPayload($this->payload([
            $receivable,
            $this->line('PL/OI/RIN/SLIC/10000', 0, 100, 'Sale to customer', 'Invoice total RM100'),
        ], [
            'counterparty' => 'Northwind Sdn Bhd',
            'document_direction' => 'outgoing',
            'payment_method' => null,
            'payment_reference' => null,
        ]));

        $this->post('/api/ocr/accounting', [
            'document' => $this->fakeDocument(),
            'accounts' => json_encode($this->accounts()),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.journal_entry.lines.0.account_name', null)
            ->assertJsonPath('data.journal_entry.lines.0.required_account.account_subtype', 'trade_receivable')
            ->assertJsonPath('data.journal_entry.lines.0.required_account.parent_code', 'BS/CA/TRV/TRDB');
    }

    public function test_it_reports_a_missing_ap_control_without_inventing_a_supplier_account(): void
    {
        $payable = $this->line(null, 0, 100, 'Amount remains payable to supplier', 'Supplier: Contoso Supplies');
        $payable['required_prefix'] = 'BS/CL/TPY/TPTC';

        $this->bindPayload($this->payload([
            $this->line('PL/OE/OEX/OPEX/10001', 100, 0, 'Expense incurred', 'Supplier invoice'),
            $payable,
        ], [
            'counterparty' => 'Contoso Supplies',
            'document_direction' => 'incoming',
            'payment_method' => null,
            'payment_reference' => null,
        ]));

        $this->post('/api/ocr/accounting', [
            'document' => $this->fakeDocument(),
            'accounts' => json_encode(array_values(array_filter($this->accounts(), fn ($a) => $a['subtype'] !== 'accounts_payable'))),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.journal_entry.lines.1.account_name', null)
            ->assertJsonPath('data.journal_entry.lines.1.required_account.account_subtype', 'trade_payable')
            ->assertJsonPath('data.journal_entry.lines.1.required_account.parent_code', 'BS/CL/TPY/TPTC');
    }

    public function test_it_uses_pdf_classification_for_missing_roles_and_accepts_legacy_accounts(): void
    {
        $accounts = array_values(array_filter(
            $this->accounts(),
            static fn (array $account): bool => $account['code'] !== 'PL/OI/RIN/SLIC/10000'
        ));
        $accounts[] = ['code' => 'PL/OI/TIN/SLIC/10010', 'name' => 'Legacy Product Sales', 'type' => 'revenue', 'subtype' => 'other'];
        $sales = $this->line(null, 0, 80, 'A new sales account is needed', 'Product sale');
        $sales['required_prefix'] = 'PL/OI/RIN/SLIC';

        $this->bindPayload($this->payload([
            $this->line('BS/CA/CNB/BANK/10000', 80, 0, 'Money received', 'Bank receipt'),
            $sales,
        ], ['description' => 'Online product sale', 'subtotal' => 80, 'total' => 80]));

        $this->post('/api/ocr/accounting', [
            'document' => $this->fakeDocument(),
            'accounts' => json_encode($accounts),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.classification.voucher_type', 'receipt_voucher')
            ->assertJsonPath('data.journal_entry.lines.1.account_name', null)
            ->assertJsonPath('data.journal_entry.lines.1.required_account.parent_code', 'PL/TI/TIN/SLIC');
    }

    public function test_it_classifies_cash_to_bank_as_a_general_voucher_with_warning(): void
    {
        $this->bindPayload($this->payload([
            $this->line('BS/CA/CNB/BANK/10000', 200, 0, 'Deposit to bank', 'Bank deposit'),
            $this->line('BS/CA/CNB/CASH/10000', 0, 200, 'Cash transferred', 'Cash deposit'),
        ], ['description' => 'Cash deposited into bank', 'total' => 200]));

        $this->post('/api/ocr/accounting', [
            'document' => $this->fakeDocument(),
            'accounts' => json_encode($this->accounts()),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.classification.voucher_type', 'general_voucher')
            ->assertJsonPath('validation.warnings.0.code', 'cash_bank_transfer')
            ->assertJsonPath('validation.requires_manual_review', true);
    }

    public function test_it_rejects_an_invented_code_without_substituting_an_account(): void
    {
        $line = $this->line('INVENTED-999', 45, 0, 'Expense', 'Parking receipt');
        $line['required_prefix'] = 'PL/OE/OEX/OPEX';
        $this->bindPayload($this->payload([
            $line,
            $this->line('BS/CA/CNB/CASH/10000', 0, 45, 'Paid cash', 'Cash'),
        ], ['total' => 45]));

        $this->post('/api/ocr/accounting', [
            'document' => $this->fakeDocument(),
            'accounts' => json_encode($this->accounts()),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.journal_entry.lines.0.account_code', null)
            ->assertJsonPath('data.journal_entry.lines.0.account_name', null)
            ->assertJsonPath('data.journal_entry.lines.0.match_status', 'account_selection_invalid')
            ->assertJsonPath('validation.errors.0.code', 'ACCOUNT_SELECTION_INVALID');
    }

    public function test_it_preserves_foreign_currency_and_requires_review(): void
    {
        $this->bindPayload($this->payload([
            $this->line('PL/OE/OEX/OPEX/10001', 25, 0, 'Subscription', 'USD 25'),
            $this->line('BS/CA/CNB/BANK/10000', 0, 25, 'Card payment', 'USD 25'),
        ], ['currency' => 'USD', 'subtotal' => 25, 'total' => 25]));

        $this->post('/api/ocr/accounting', [
            'document' => $this->fakeDocument(),
            'accounts' => json_encode($this->accounts()),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.extracted.currency', 'USD')
            ->assertJsonPath('validation.warnings.0.code', 'foreign_currency_requires_review')
            ->assertJsonPath('validation.is_postable', false);
    }

    public function test_it_returns_an_unbalanced_proposal_for_manual_review_without_forcing_it(): void
    {
        $this->bindPayload($this->payload([
            $this->line('PL/OE/OEX/OPEX/10001', 100, 0, 'Expense', 'Invoice RM100'),
            $this->line('BS/CA/CNB/BANK/10000', 0, 90, 'Bank payment', 'Paid RM90'),
        ]));

        $this->post('/api/ocr/accounting', [
            'document' => $this->fakeDocument(),
            'accounts' => json_encode($this->accounts()),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.journal_entry.total_debit', 100)
            ->assertJsonPath('data.journal_entry.total_credit', 90)
            ->assertJsonPath('data.journal_entry.is_balanced', false)
            ->assertJsonPath('validation.errors.0.code', 'unbalanced_entry')
            ->assertJsonPath('validation.is_postable', false);
    }

    public function test_it_marks_low_confidence_extraction_for_review(): void
    {
        $payload = $this->payload([
            $this->line('PL/OE/OEX/OPEX/10001', 100, 0, 'Expense', 'Invoice'),
            $this->line('BS/CA/CNB/BANK/10000', 0, 100, 'Bank payment', 'Bank'),
        ]);
        $payload['overall_confidence'] = 0.4;
        $this->bindPayload($payload);

        $this->post('/api/ocr/accounting', [
            'document' => $this->fakeDocument(),
            'accounts' => json_encode($this->accounts()),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('validation.warnings.0.code', 'low_confidence')
            ->assertJsonPath('validation.requires_manual_review', true);
    }

    public function test_it_marks_a_low_confidence_account_match_for_review(): void
    {
        $expense = $this->line('PL/OE/OEX/OPEX/10001', 100, 0, 'Expense', 'Invoice');
        $expense['confidence'] = 0.5;
        $this->bindPayload($this->payload([
            $expense,
            $this->line('BS/CA/CNB/BANK/10000', 0, 100, 'Bank payment', 'Bank'),
        ]));

        $this->post('/api/ocr/accounting', [
            'document' => $this->fakeDocument(),
            'accounts' => json_encode($this->accounts()),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('validation.warnings.0.code', 'low_account_match_confidence')
            ->assertJsonPath('validation.is_postable', false);
    }

    public function test_it_rejects_multiple_unrelated_transactions(): void
    {
        $payload = $this->payload([$this->line('BS/CA/CNB/BANK/10000', 10, 0, 'Receipt', 'Receipt')]);
        $payload['transaction_count'] = 2;
        $this->bindPayload($payload);

        $this->post('/api/ocr/accounting', [
            'document' => $this->fakeDocument(),
            'accounts' => json_encode($this->accounts()),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'multiple_transactions_detected');
    }

    public function test_it_validates_duplicate_codes_and_document_mime(): void
    {
        $duplicate = $this->accounts()[0];
        $duplicate['code'] = strtolower($duplicate['code']);
        $accounts = [$this->accounts()[0], $duplicate];

        $this->post('/api/ocr/accounting', [
            'document' => UploadedFile::fake()->createWithContent('bad.exe', 'MZ executable'),
            'accounts' => json_encode($accounts),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['document', 'accounts.1.code']);
    }

    public function test_it_rejects_more_than_the_configured_account_limit(): void
    {
        $accounts = [];
        for ($index = 1; $index <= 501; $index++) {
            $accounts[] = [
                'code' => 'ACCOUNT-'.$index,
                'name' => 'Account '.$index,
                'type' => 'expense',
                'subtype' => 'other',
            ];
        }

        $this->post('/api/ocr/accounting', [
            'document' => $this->fakeDocument(),
            'accounts' => json_encode($accounts),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['accounts']);
    }

    public function test_it_does_not_log_account_or_transaction_contents(): void
    {
        Log::spy();
        $payload = $this->payload([
            $this->line('PL/OE/OEX/OPEX/10001', 100, 0, 'SECRET EXPENSE', 'SECRET-REFERENCE-123'),
            $this->line('BS/CA/CNB/BANK/10000', 0, 100, 'SECRET BANK', 'SECRET BANK EVIDENCE'),
        ], ['counterparty' => 'SECRET SUPPLIER SDN BHD', 'reference_number' => 'SECRET-REFERENCE-123']);
        $this->bindPayload($payload);

        $this->post('/api/ocr/accounting', [
            'document' => $this->fakeDocument(),
            'accounts' => json_encode($this->accounts()),
        ], ['Accept' => 'application/json'])->assertOk();

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
            $encoded = json_encode($context) ?: '';

            return ! str_contains($encoded, 'SECRET')
                && ! str_contains($encoded, 'Maybank')
                && ! str_contains($encoded, 'Office Expenses');
        })->atLeast()->once();
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $lines, array $overrides = []): array
    {
        return array_merge([
            'document_kind' => 'invoice',
            'economic_event' => 'Ordinary purchase or sale',
            'trade_nature' => 'non_trade',
            'treatment_summary' => 'Recognise the supported transaction using eligible accounts.',
            'payment_status' => 'paid',
            'payment_status_evidence' => 'Receipt confirms settlement.',
            'transaction_count' => 1,
            'transaction_date' => '2026-09-17',
            'reference_number' => 'PV-1001',
            'counterparty' => 'Example Supplier',
            'description' => 'Office purchase',
            'document_direction' => 'unknown',
            'currency' => 'MYR',
            'subtotal' => 100,
            'tax' => 0,
            'total' => 100,
            'payment_method' => null,
            'payment_reference' => null,
            'overall_confidence' => 0.95,
            'lines' => $lines,
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
        ], $overrides);
    }

    public function test_it_resolves_msic_and_returns_system_usage_examples(): void
    {
        $this->bindPayload($this->payload([
            $this->line('PL/OE/OEX/OPEX/10001', 100, 0, 'Expense', 'Invoice'),
            $this->line('BS/CA/CNB/BANK/10000', 0, 100, 'Paid', 'Bank payment'),
        ]));
        $this->post('/api/ocr/accounting', [
            'document' => $this->fakeDocument(),
            'accounts' => json_encode($this->accounts()),
            'msic_code' => '01111',
            'additional_msic_codes' => '["62010"]',
            'business_description' => 'We grow maize and develop software.',
            'posting_date' => '2026-10-07',
        ])->assertOk()
            ->assertJsonPath('data.business_context.msic.0.code', '01111')
            ->assertJsonPath('data.business_context.msic.0.description', 'Growing of maize')
            ->assertJsonPath('data.business_context.msic.1.description', 'Computer programming activities')
            ->assertJsonPath('data.journal_entry.lines.0.account_guidance.parent_code', 'PL/TE/TEX/OPEX')
            ->assertJsonCount(1, 'data.journal_entry.lines.0.account_guidance.usage_examples');
    }

    public function test_it_rejects_unknown_msic_and_invalid_posting_dates(): void
    {
        $this->post('/api/ocr/accounting', [
            'document' => $this->fakeDocument(), 'accounts' => $this->accounts(),
            'msic_code' => '99998', 'additional_msic_codes' => ['62010', 'bad'],
            'posting_date' => '2026-02-30',
        ])->assertStatus(422)->assertJsonValidationErrors(['msic_code', 'additional_msic_codes.1', 'posting_date']);
    }

    public function test_it_exposes_the_full_pdf_account_library(): void
    {
        $this->getJson('/api/ocr/accounting/accounts')->assertOk()
            ->assertJsonCount(80, 'data.accounts')
            ->assertJsonPath('data.accounts.BS/CA/ORV/PRMT.subtype', 'prepayment')
            ->assertJsonPath('data.accounts.PL/TI/TIN/SLIC.type', 'revenue');
    }

    public function test_it_renders_the_updated_accounting_documentation(): void
    {
        $this->withSession(['docs_unlocked' => true])->get('/docs')->assertOk()
            ->assertSee('Account category library')->assertSee('additional_msic_codes')
            ->assertSee('prepayment_assessment');
    }

    public function test_it_reviews_matched_prepaid_accounts_without_generating_future_journals(): void
    {
        $accounts = $this->accounts();
        $accounts[] = ['code' => 'BS/CA/ORV/PRMT/10000', 'name' => 'Prepaid Insurance', 'type' => 'asset', 'subtype' => 'other'];
        $this->bindPayload($this->payload([
            $this->line('BS/CA/ORV/PRMT/10000', 1200, 0, 'Future insurance', 'Annual insurance'),
            $this->line('BS/CA/CNB/BANK/10000', 0, 1200, 'Paid', 'Bank payment'),
        ], [
            'total' => 1200, 'subtotal' => 1200, 'payment_status' => 'paid',
            'payment_status_evidence' => 'Paid RM1200',
            'service_periods' => [[
                'description' => 'Insurance', 'amount' => 1200,
                'start_date' => '2027-01-01', 'end_date' => '2027-12-31',
                'period_kind' => 'service_coverage', 'evidence' => 'Coverage Jan-Dec 2027',
                'prepayment_candidate' => false,
            ]],
        ]));
        $this->post('/api/ocr/accounting', [
            'document' => $this->fakeDocument(), 'accounts' => $accounts, 'posting_date' => '2026-10-07',
        ])->assertOk()
            ->assertJsonPath('validation.is_postable', false)
            ->assertJsonPath('data.prepayment_assessment.has_prepayment_account', true)
            ->assertJsonPath('data.prepayment_assessment.service_periods.0.relation_to_posting_date', 'future')
            ->assertJsonPath('data.prepayment_assessment.service_periods.0.prepayment_candidate', true)
            ->assertJsonPath('data.prepayment_assessment.schedule_generated', false)
            ->assertJsonCount(2, 'data.journal_entry.lines');
    }

    public function test_sample_trade_purchase_cannot_use_other_creditors_or_a_supplier_ledger(): void
    {
        $accounts = $this->accounts();
        $accounts[3]['is_control_account'] = false;
        unset($accounts[3]['control_role']);
        $accounts[] = ['code' => 'BS/CL/OPY/OPCR/10000', 'name' => 'Other Creditors', 'type' => 'liability', 'subtype' => 'other_payable'];
        $payable = $this->line('BS/CL/OPY/OPCR/10000', 0, 1721.24, 'Unsettled ingredients purchase', 'Seafood invoice; COD terms alone do not prove payment');
        $payable['required_role'] = 'trade_payable';
        $payable['required_account_type'] = 'liability';
        $payable['required_prefix'] = 'BS/CL/TPY/TPTC';
        $this->bindPayload($this->payload([
            $this->line('PL/OE/OEX/OPEX/10001', 1721.24, 0, 'Consumed ingredients under company policy', 'Seafood items'),
            $payable,
        ], ['trade_nature' => 'trade', 'total' => 1721.24, 'payment_status' => 'unknown', 'payment_status_evidence' => null]));
        $this->post('/api/ocr/accounting', [
            'document' => $this->fakeDocument(), 'accounts' => $accounts,
            'msic_code' => '56101', 'business_description' => 'Restaurant; evaluation context.',
        ])->assertOk()
            ->assertJsonPath('data.journal_entry.lines.1.error_code', 'ACCOUNT_NOT_AVAILABLE')
            ->assertJsonPath('data.journal_entry.lines.1.recommendation.control_role', 'ap')
            ->assertJsonPath('data.journal_entry.lines.1.recommendation.requires_control_account', true)
            ->assertJsonPath('data.journal_entry.lines.1.required_account.control_role', 'ap')
            ->assertJsonPath('data.journal_entry.lines.1.account_code', null)
            ->assertJsonPath('data.journal_entry.is_complete', false)
            ->assertJsonPath('validation.is_postable', false);
    }

    public function test_trade_treatment_cannot_be_changed_to_fit_other_creditors(): void
    {
        $accounts = $this->accounts();
        $accounts[] = ['code' => 'BS/CL/OPY/OPCR/10000', 'name' => 'Other Creditors', 'type' => 'liability', 'subtype' => 'other_payable'];
        $this->bindPayload($this->payload([
            $this->line('PL/OE/OEX/OPEX/10001', 100, 0, 'Trade purchase', 'Ingredients'),
            $this->line('BS/CL/OPY/OPCR/10000', 0, 100, 'Wrong non-trade treatment', 'Supplier invoice'),
        ], ['trade_nature' => 'trade', 'payment_status' => 'unpaid']));
        $this->post('/api/ocr/accounting', ['document' => $this->fakeDocument(), 'accounts' => $accounts])
            ->assertOk()->assertJsonPath('data.journal_entry.lines.1.error_code', 'ACCOUNT_TREATMENT_CONFLICT')
            ->assertJsonPath('validation.is_postable', false);
    }

    public function test_sample_supplier_credit_note_preserves_reference_and_reversal_sides(): void
    {
        $this->bindPayload($this->payload([
            $this->line('BS/CL/TPY/TPTC/10000', 423.60, 0, 'Reduce supplier liability', 'Goods credit adjustment'),
            $this->line('PL/OE/OEX/OPEX/10001', 0, 423.60, 'Reverse original expense under company policy', 'Returned goods'),
        ], [
            'document_kind' => 'credit_note', 'trade_nature' => 'trade', 'document_direction' => 'incoming',
            'transaction_date' => '2026-01-27', 'original_document_reference' => 'ORIGINAL-INVOICE',
            'total' => 423.60, 'payment_status' => 'unpaid', 'payment_status_evidence' => 'Adjustment against outstanding supplier balance',
        ]));
        $this->post('/api/ocr/accounting', ['document' => $this->fakeDocument(), 'accounts' => $this->accounts()])
            ->assertOk()->assertJsonPath('data.classification.document_kind', 'credit_note')
            ->assertJsonPath('data.extracted.original_document_reference', 'ORIGINAL-INVOICE')
            ->assertJsonPath('data.journal_entry.lines.0.debit', 423.6)
            ->assertJsonPath('data.journal_entry.lines.0.required_account.control_role', 'ap')
            ->assertJsonPath('data.journal_entry.is_complete', true)
            ->assertJsonPath('validation.final_validation_required_by', 'Arkcloudant');
    }

    public function test_sample_insurance_period_requires_review_without_assuming_payment(): void
    {
        $accounts = $this->accounts();
        $accounts[] = ['code' => 'BS/CA/ORV/PRMT/10000', 'name' => 'Prepaid Insurance', 'type' => 'asset', 'subtype' => 'prepayment'];
        $this->bindPayload($this->payload([
            $this->line('BS/CA/ORV/PRMT/10000', 298.59, 0, 'Coverage timing proposal requires review', 'Annual policy'),
            $this->line('BS/CL/TPY/TPTC/10000', 0, 298.59, 'Proposed payable pending review', 'Receipt will be issued upon payment'),
        ], [
            'document_kind' => 'debit_note', 'transaction_count' => 1,
            'transaction_date' => '2023-01-13', 'invoice_date' => '2023-01-13', 'total' => 298.59,
            'payment_status' => 'unknown', 'payment_status_evidence' => null,
            'service_periods' => [[
                'description' => 'Insurance', 'amount' => 298.59,
                'start_date' => '2023-01-13', 'end_date' => '2024-01-12',
                'period_kind' => 'service_coverage', 'evidence' => 'Policy coverage dates', 'prepayment_candidate' => true,
            ]],
        ]));
        $this->post('/api/ocr/accounting', ['document' => $this->fakeDocument(), 'accounts' => $accounts, 'posting_date' => '2023-01-13'])
            ->assertOk()->assertJsonPath('data.prepayment_assessment.payment_status', 'unknown')
            ->assertJsonPath('data.prepayment_assessment.service_periods.0.relation_to_posting_date', 'covers_posting_date')
            ->assertJsonPath('data.prepayment_assessment.schedule_generated', false)
            ->assertJsonPath('validation.requires_manual_review', true)
            ->assertJsonPath('validation.is_postable', false);
    }

    public function test_empty_chart_returns_requirements_instead_of_creating_accounts(): void
    {
        $this->bindPayload($this->payload([
            $this->line('PL/OE/OEX/OPEX/10001', 100, 0, 'Expense required', 'Invoice'),
            $this->line('BS/CL/TPY/TPTC/10000', 0, 100, 'AP control required', 'Unpaid invoice'),
        ]));
        $this->post('/api/ocr/accounting', ['document' => $this->fakeDocument(), 'accounts' => '[]'])
            ->assertOk()->assertJsonPath('data.journal_entry.lines.0.error_code', 'ACCOUNT_NOT_AVAILABLE')
            ->assertJsonPath('data.journal_entry.lines.1.error_code', 'ACCOUNT_NOT_AVAILABLE')
            ->assertJsonPath('validation.is_postable', false);
    }

    public function test_control_metadata_and_source_roles_must_be_consistent(): void
    {
        $accounts = $this->accounts();
        $accounts[0]['role'] = 'trade_payable';
        $accounts[3]['control_role'] = 'ar';
        $this->post('/api/ocr/accounting', ['document' => $this->fakeDocument(), 'accounts' => $accounts], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors(['accounts.0.role', 'accounts.3.control_role']);

        $accounts = $this->accounts();
        unset($accounts[3]['is_control_account']);
        $this->post('/api/ocr/accounting', ['document' => $this->fakeDocument(), 'accounts' => $accounts], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors(['accounts.3.is_control_account']);
    }

    /** @return array<string, mixed> */
    private function line(?string $code, float $debit, float $credit, string $reason, string $evidence): array
    {
        return [
            'selected_account_code' => $code,
            'required_account_type' => null,
            'required_prefix' => null,
            'debit' => $debit,
            'credit' => $credit,
            'confidence' => 0.95,
            'reason' => $reason,
            'evidence' => $evidence,
        ];
    }
}
