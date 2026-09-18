<?php

namespace Tests\Feature;

use App\Contracts\Ocr\AccountingOcrClient;
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

            public function extract(string $documentContent, string $mimeType, array $accounts, ?string $companyContext = null): array
            {
                $payload = $this->payload;
                if ($companyContext === 'ACME SDN BHD - our company and invoice issuer') {
                    $payload['document_direction'] = 'outgoing';
                }

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
            ['code' => 'BS/CL/TPY/TPTC/10000', 'name' => 'Trade Payables', 'type' => 'liability', 'subtype' => 'accounts_payable', 'aliases' => []],
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
            ->assertJsonPath('validation.is_postable', true)
            ->assertJsonPath('validation.requires_manual_review', false)
            ->assertJsonPath('meta.provider', 'gemini');
    }

    public function test_it_passes_free_form_company_context_and_returns_document_direction(): void
    {
        $this->bindPayload($this->payload([
            $this->line('BS/CA/CNB/BANK/10000', 100, 0, 'Customer paid us', 'Paid RM100'),
            $this->line('PL/OI/RIN/SLIC/10000', 0, 100, 'Sale to customer', 'Invoice total RM100'),
        ]));

        $this->post('/api/ocr/accounting', [
            'document' => $this->fakeDocument(),
            'company' => 'ACME SDN BHD - our company and invoice issuer',
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

    public function test_it_recommends_an_explicit_output_tax_account(): void
    {
        $tax = $this->line(null, 0, 6, 'Explicit SST is payable', 'SST 6.00');
        $tax['suggested_name'] = 'SST Payable';
        $tax['suggested_prefix'] = 'BS/CL/CTL/SNTP';

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
            ->assertJsonPath('data.journal_entry.lines.2.recommendation.provisional_code', 'BS/CL/CTL/SNTP/10000')
            ->assertJsonPath('data.journal_entry.lines.2.recommendation.account_subtype', 'output_tax')
            ->assertJsonMissing(['code' => 'tax_account_missing']);
    }

    public function test_it_recommends_the_next_user_defined_account_code_without_reusing_gaps(): void
    {
        $accounts = $this->accounts();
        $accounts[] = ['code' => 'PL/OE/OEX/OPEX/10003', 'name' => 'Utilities', 'type' => 'expense', 'subtype' => 'other'];
        $missing = $this->line(null, 100, 0, 'No submitted account is suitable', 'Annual cloud software subscription');
        $missing['suggested_name'] = 'Cloud Software Subscription';
        $missing['suggested_account_type'] = 'expense';
        $missing['suggested_prefix'] = 'PL/OE/OEX/OPEX';

        $this->bindPayload($this->payload([
            $missing,
            $this->line('BS/CA/CNB/BANK/10000', 0, 100, 'Paid from bank', 'Bank payment'),
        ]));

        $this->post('/api/ocr/accounting', [
            'document' => $this->fakeDocument(),
            'accounts' => json_encode($accounts),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.journal_entry.lines.0.match_status', 'new_account_recommended')
            ->assertJsonPath('data.journal_entry.lines.0.recommendation.provisional_code', 'PL/OE/OEX/OPEX/10004')
            ->assertJsonPath('data.journal_entry.lines.0.recommendation.normal_balance', 'debit')
            ->assertJsonPath('validation.is_postable', false)
            ->assertJsonPath('validation.requires_manual_review', true);
    }

    public function test_it_allocates_unique_codes_for_multiple_recommendations_in_one_category(): void
    {
        $first = $this->line(null, 60, 0, 'First missing expense', 'Software RM60');
        $first['suggested_name'] = 'Software Subscription';
        $first['suggested_prefix'] = 'PL/OE/OEX/OPEX';
        $second = $this->line(null, 40, 0, 'Second missing expense', 'Parking RM40');
        $second['suggested_name'] = 'Parking Expense';
        $second['suggested_prefix'] = 'PL/OE/OEX/OPEX';

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
            ->assertJsonPath('data.journal_entry.lines.0.recommendation.provisional_code', 'PL/OE/OEX/OPEX/10002')
            ->assertJsonPath('data.journal_entry.lines.1.recommendation.provisional_code', 'PL/OE/OEX/OPEX/10003');
    }

    public function test_it_preserves_an_existing_tin_revenue_prefix_convention(): void
    {
        $accounts = array_values(array_filter(
            $this->accounts(),
            static fn (array $account): bool => $account['code'] !== 'PL/OI/RIN/SLIC/10000'
        ));
        $accounts[] = ['code' => 'PL/OI/TIN/SLIC/10010', 'name' => 'Legacy Product Sales', 'type' => 'revenue', 'subtype' => 'other'];
        $sales = $this->line(null, 0, 80, 'A new sales account is needed', 'Product sale');
        $sales['suggested_name'] = 'Online Product Sales';
        $sales['suggested_prefix'] = 'PL/OI/RIN/SLIC';

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
            ->assertJsonPath('data.journal_entry.lines.1.recommendation.provisional_code', 'PL/OI/TIN/SLIC/10011');
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

    public function test_it_rejects_an_invented_selected_code_and_uses_a_valid_recommendation(): void
    {
        $line = $this->line('INVENTED-999', 45, 0, 'Expense', 'Parking receipt');
        $line['suggested_name'] = 'Parking Expense';
        $line['suggested_prefix'] = 'PL/OE/OEX/OPEX';
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
            ->assertJsonPath('data.journal_entry.lines.0.match_status', 'new_account_recommended')
            ->assertJsonPath('validation.warnings.0.code', 'invalid_selected_account');
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

    /** @return array<string, mixed> */
    private function line(?string $code, float $debit, float $credit, string $reason, string $evidence): array
    {
        return [
            'selected_account_code' => $code,
            'suggested_name' => null,
            'suggested_account_type' => null,
            'suggested_prefix' => null,
            'debit' => $debit,
            'credit' => $credit,
            'confidence' => 0.95,
            'reason' => $reason,
            'evidence' => $evidence,
        ];
    }
}
