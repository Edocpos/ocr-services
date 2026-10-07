<?php

namespace App\Http\Requests;

use App\Services\AccountingOcr\MsicRegistry;
use App\Support\OcrDocumentMime;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ProcessAccountingOcrRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['accounts', 'additional_msic_codes'] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $this->merge([$field => $decoded]);
                }
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'document' => ['required', 'file', 'max:'.(int) config('ocr.accounting_max_file_size_kb', 10240)],
            'msic_code' => ['nullable', 'string', 'regex:/^[0-9]{5}$/'],
            'additional_msic_codes' => ['sometimes', 'array', 'list', 'max:10'],
            'additional_msic_codes.*' => ['required', 'string', 'regex:/^[0-9]{5}$/', 'distinct:strict'],
            'business_description' => ['nullable', 'string', 'max:2000'],
            'posting_date' => ['nullable', 'date_format:Y-m-d'],
            'company' => ['nullable', 'string', 'max:10000'],
            'accounts' => ['required', 'array', 'list', 'min:1', 'max:'.(int) config('ocr.accounting_max_accounts', 500)],
            'accounts.*.code' => ['required', 'string', 'max:100', 'distinct:strict'],
            'accounts.*.name' => ['required', 'string', 'max:255'],
            'accounts.*.type' => ['required', Rule::in(['asset', 'liability', 'equity', 'revenue', 'expense'])],
            'accounts.*.subtype' => ['required', Rule::in(['cash', 'bank', 'accounts_receivable', 'accounts_payable', 'trade_receivable', 'trade_payable', 'input_tax', 'output_tax', 'prepayment', 'supplier_advance', 'customer_advance', 'refundable_deposit', 'accrual', 'other_payable', 'income_tax_asset', 'income_tax_payable', 'other'])],
            'accounts.*.purpose' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'accounts.*.usage_examples' => ['sometimes', 'array', 'list', 'max:3'],
            'accounts.*.usage_examples.*' => ['string', 'max:500'],
            'accounts.*.aliases' => ['sometimes', 'array', 'max:20'],
            'accounts.*.aliases.*' => ['string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'document.required' => 'The document field is required.',
            'document.file' => 'The document must be a PDF, JPG, PNG or WebP file.',
            'document.max' => 'The document must not be greater than 10 MB.',
            'accounts.array' => 'The accounts field must be a valid JSON array.',
            'accounts.*.code.distinct' => 'Every account code must be unique.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $registry = app(MsicRegistry::class);
            $codes = ['msic_code' => $this->input('msic_code')];
            if (is_array($this->input('additional_msic_codes'))) {
                foreach ($this->input('additional_msic_codes') as $index => $code) {
                    $codes['additional_msic_codes.'.$index] = $code;
                }
            }
            foreach ($codes as $field => $code) {
                if (is_string($code) && $code !== '' && $registry->find($code) === null) {
                    $validator->errors()->add($field, 'The MSIC code is not in the supported catalogue.');
                }
            }

            $seenCodes = [];
            foreach ((array) $this->input('accounts', []) as $index => $account) {
                if (! is_array($account) || ! is_string($account['code'] ?? null)) {
                    continue;
                }

                $normalizedCode = mb_strtoupper(trim($account['code']));
                if (isset($seenCodes[$normalizedCode])) {
                    $validator->errors()->add('accounts.'.$index.'.code', 'Every account code must be unique.');
                }
                $seenCodes[$normalizedCode] = true;
            }

            $file = $this->file('document');
            if ($file === null || $file->getRealPath() === false) {
                return;
            }

            $content = @file_get_contents($file->getRealPath());
            if ($content === false || ! OcrDocumentMime::isAllowed($content, [
                'application/pdf',
                'image/jpeg',
                'image/png',
                'image/webp',
            ])) {
                $validator->errors()->add('document', 'The document must be a PDF, JPG, PNG or WebP file.');
            }
        });
    }

    /** @return list<array<string, mixed>> */
    public function accounts(): array
    {
        return array_map(
            fn (array $account): array => array_intersect_key($account, array_flip(['code', 'name', 'type', 'subtype', 'aliases', 'purpose', 'usage_examples'])),
            array_values($this->validated('accounts')),
        );
    }

    public function companyContext(): ?string
    {
        $company = $this->validated('company');

        return is_string($company) && $company !== '' ? $company : null;
    }

    /** Resolved business codes, not the full catalogue, are sent to the provider. */
    public function accountingContext(): array
    {
        $registry = app(MsicRegistry::class);
        $codes = array_values(array_unique(array_filter([
            $this->validated('msic_code'),
            ...($this->validated('additional_msic_codes') ?? []),
        ], fn ($code) => is_string($code) && $code !== '')));

        return [
            'msic' => array_map(fn (string $code): array => $registry->find($code), $codes),
            'msic_catalogue_version' => $registry->catalogue()['version'],
            'business_description' => $this->validated('business_description'),
            'posting_date' => $this->validated('posting_date'),
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'The accounting OCR request is invalid.',
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }
}
