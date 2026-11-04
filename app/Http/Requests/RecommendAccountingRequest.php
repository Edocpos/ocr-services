<?php

namespace App\Http\Requests;

use App\Services\AccountingOcr\MsicRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RecommendAccountingRequest extends FormRequest
{
    public const KINDS = [
        'customer_invoice', 'customer_credit_note', 'customer_debit_note', 'customer_receipt', 'customer_refund',
        'supplier_bill', 'supplier_credit_note', 'supplier_debit_note', 'supplier_payment', 'supplier_refund',
    ];

    private const COMMERCIAL = ['customer_invoice', 'customer_credit_note', 'customer_debit_note', 'supplier_bill', 'supplier_credit_note', 'supplier_debit_note'];

    private const ALLOCATED = ['customer_receipt', 'supplier_payment'];

    private const HINTS = ['default_ar_account_code', 'default_revenue_account_code', 'default_ap_account_code', 'default_expense_account_code', 'default_bank_account_code'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $kind = $this->input('document_kind');
        $commercial = in_array($kind, self::COMMERCIAL, true);
        $datedField = match ($kind) {
            'customer_receipt' => 'receipt_date',
            'supplier_payment' => 'payment_date',
            'customer_refund', 'supplier_refund' => 'refund_date',
            default => 'document_date',
        };
        $rules = [
            'document_kind' => ['required', Rule::in(self::KINDS)],
            'workflow_context' => ['required', 'string', 'same:document_kind'],
            'currency' => ['required', 'in:MYR'],
            'company' => ['sometimes', 'array'],
            'company.name' => ['sometimes', 'string', 'max:255'],
            'company.registration_number' => ['sometimes', 'string', 'max:100'],
            'msic_code' => ['sometimes', 'string', 'regex:/^[0-9]{5}$/'],
            'additional_msic_codes' => ['sometimes', 'array', 'list', 'max:10'],
            'additional_msic_codes.*' => ['required', 'string', 'regex:/^[0-9]{5}$/', 'distinct:strict'],
            'hints' => ['sometimes', 'array'],
            'counterparty' => ['required', 'array'],
            'counterparty.role' => ['required', Rule::in(['customer', 'supplier'])],
            'counterparty.name' => ['required', 'string', 'max:255'],
            'counterparty.external_ref' => ['sometimes', 'string', 'max:100'],
            'counterparty.tax_id' => ['sometimes', 'string', 'max:100'],
            'document' => ['required', 'array'],
            'document.'.$datedField => ['required', 'date_format:Y-m-d'],
            'document.reference' => ['sometimes', 'string', 'max:255'],
            'document.description' => ['sometimes', 'string', 'max:2000'],
            'document.payment_method' => ['sometimes', 'string', 'max:100'],
            'document.bank_reference' => ['sometimes', 'string', 'max:255'],
            'document.refund_of' => ['sometimes', 'string', 'max:255'],
            'document.original_invoice_reference' => ['sometimes', 'string', 'max:255'],
            'document.original_bill_reference' => ['sometimes', 'string', 'max:255'],
            'accounts' => ['required', 'array', 'list', 'min:1', 'max:500'],
            'accounts.*.code' => ['required', 'string', 'max:100', 'distinct:strict'],
            'accounts.*.name' => ['required', 'string', 'max:255'],
            'accounts.*.type' => ['required', Rule::in(['asset', 'liability', 'equity', 'revenue', 'expense'])],
            'accounts.*.subtype' => ['required', 'string', 'max:100'],
            'accounts.*.aliases' => ['sometimes', 'array', 'list', 'max:20'],
            'accounts.*.aliases.*' => ['required', 'string', 'max:255'],
        ];
        foreach (self::HINTS as $hint) {
            $rules['hints.'.$hint] = ['sometimes', 'string', 'max:100'];
        }
        if ($commercial) {
            $rules += [
                'document.due_date' => ['sometimes', 'date_format:Y-m-d'],
                'document.subtotal' => ['required', 'string', 'max:15', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'],
                'document.tax' => ['required', 'string', 'max:15', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'],
                'document.total' => ['required', 'string', 'max:15', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'],
                'document.lines' => ['required', 'array', 'list', 'min:1', 'max:500'],
                'document.lines.*.line_no' => ['required', 'integer', 'min:1'],
                'document.lines.*.description' => ['required', 'string', 'max:1000'],
                'document.lines.*.quantity' => ['required', 'regex:/^\d+(?:\.\d+)?$/'],
                'document.lines.*.unit_price' => ['required', 'string', 'max:15', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'],
                'document.lines.*.amount' => ['required', 'string', 'max:15', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'],
            ];
        } else {
            $rules['document.amount'] = ['required', 'string', 'max:15', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'];
        }
        if (in_array($kind, self::ALLOCATED, true)) {
            $rules['document.unapplied_amount'] = ['sometimes', 'string', 'max:15', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'];
        }
        if (in_array($kind, [...self::ALLOCATED, 'customer_credit_note', 'supplier_credit_note'], true)) {
            $rules['document.allocations'] = [in_array($kind, self::ALLOCATED, true) ? 'present' : 'sometimes', 'array', 'list', 'max:500'];
        } elseif (in_array($kind, ['customer_refund', 'supplier_refund'], true)) {
            $rules['document.allocations'] = ['sometimes', 'array', 'list', 'max:500'];
        }
        if (isset($rules['document.allocations'])) {
            $rules += [
                'document.allocations.*.target_document_kind' => ['required', Rule::in(self::KINDS)],
                'document.allocations.*.target_reference' => ['required', 'string', 'max:255'],
                'document.allocations.*.allocated_amount' => ['required', 'string', 'max:15', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/'],
            ];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->isJson()) {
                $validator->errors()->add('content_type', 'The request must use application/json.');
            }
            $kind = $this->input('document_kind');
            if (in_array($kind, self::KINDS, true)) {
                $role = str_starts_with($kind, 'customer_') ? 'customer' : 'supplier';
                if ($this->input('counterparty.role') !== $role) {
                    $validator->errors()->add('counterparty.role', 'The counterparty role must match document_kind.');
                }
            }
            $registry = app(MsicRegistry::class);
            foreach (['msic_code' => $this->input('msic_code'), ...array_combine(
                array_map(fn ($i) => 'additional_msic_codes.'.$i, array_keys((array) $this->input('additional_msic_codes', []))),
                array_values((array) $this->input('additional_msic_codes', [])),
            )] as $field => $code) {
                if (is_string($code) && preg_match('/^\d{5}$/', $code) && $registry->find($code) === null) {
                    $validator->errors()->add($field, 'The MSIC code is not in the supported catalogue.');
                }
            }
            $accounts = (array) $this->input('accounts', []);
            $seen = [];
            foreach ($accounts as $index => $account) {
                if (! is_array($account) || ! is_string($account['code'] ?? null)) {
                    continue;
                }
                $key = mb_strtoupper(trim($account['code']));
                if (isset($seen[$key])) {
                    $validator->errors()->add('accounts.'.$index.'.code', 'Every account code must be unique.');
                }
                $seen[$key] = true;
            }
            foreach ((array) $this->input('hints', []) as $key => $code) {
                if (in_array($key, self::HINTS, true) && is_string($code) && ! isset($seen[mb_strtoupper(trim($code))])) {
                    $validator->errors()->add('hints.'.$key, 'The hinted code must appear in accounts.');
                }
            }
            $document = (array) $this->input('document', []);
            if (! in_array($kind, self::COMMERCIAL, true) && $this->cents($document['amount'] ?? null) === 0) {
                $validator->errors()->add('document.amount', 'Amount must be greater than zero.');
            }
            if (in_array($kind, self::COMMERCIAL, true)) {
                if ($this->cents($document['total'] ?? null) === 0) {
                    $validator->errors()->add('document.total', 'Total must be greater than zero.');
                }
                if (isset($document['subtotal'], $document['tax'], $document['total'])
                    && $this->cents($document['subtotal']) !== null && $this->cents($document['tax']) !== null && $this->cents($document['total']) !== null
                    && $this->cents($document['subtotal']) + $this->cents($document['tax']) !== $this->cents($document['total'])) {
                    $validator->errors()->add('document.total', 'Total must equal subtotal plus tax.');
                }
                $sum = 0;
                foreach ((array) ($document['lines'] ?? []) as $index => $line) {
                    if (is_array($line)) {
                        $sum += $this->cents($line['amount'] ?? null) ?? 0;
                        if (($line['line_no'] ?? null) !== $index + 1) {
                            $validator->errors()->add('document.lines.'.$index.'.line_no', 'Line numbers must be sequential, starting at 1.');
                        }
                    }
                }
                if ($document['lines'] ?? false) {
                    if ($sum !== $this->cents($document['subtotal'] ?? null)) {
                        $validator->errors()->add('document.lines', 'Line amounts must equal subtotal.');
                    }
                }
            }
            $allocationSum = 0;
            foreach ((array) ($document['allocations'] ?? []) as $allocation) {
                if (is_array($allocation)) {
                    if ($this->cents($allocation['allocated_amount'] ?? null) === 0) {
                        $validator->errors()->add('document.allocations', 'Allocated amounts must be greater than zero.');
                    }
                    $allocationSum += $this->cents($allocation['allocated_amount'] ?? null) ?? 0;
                }
            }
            $authority = $this->cents($document[in_array($kind, self::COMMERCIAL, true) ? 'total' : 'amount'] ?? null);
            if ($authority !== null && $allocationSum > $authority) {
                $validator->errors()->add('document.allocations', 'Allocations cannot exceed the document amount.');
            }
            if (in_array($kind, self::ALLOCATED, true) && $authority !== null && isset($document['unapplied_amount'])) {
                $unapplied = $this->cents($document['unapplied_amount'] ?? '0.00');
                if ($unapplied !== null && $allocationSum + $unapplied !== $authority) {
                    $validator->errors()->add('document.unapplied_amount', 'Allocations plus unapplied amount must equal amount.');
                }
            }
        });
    }

    private function cents(mixed $value): ?int
    {
        if (! is_string($value) || ! preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $value)) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }
}
