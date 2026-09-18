<?php

namespace App\Http\Requests;

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
        $accounts = $this->input('accounts');
        if (is_string($accounts)) {
            $decoded = json_decode($accounts, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $this->merge(['accounts' => $decoded]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'document' => ['required', 'file', 'max:'.(int) config('ocr.accounting_max_file_size_kb', 10240)],
            'company' => ['nullable', 'string', 'max:10000'],
            'accounts' => ['required', 'array', 'min:1', 'max:'.(int) config('ocr.accounting_max_accounts', 500)],
            'accounts.*.code' => ['required', 'string', 'max:100', 'distinct:strict'],
            'accounts.*.name' => ['required', 'string', 'max:255'],
            'accounts.*.type' => ['required', Rule::in(['asset', 'liability', 'equity', 'revenue', 'expense'])],
            'accounts.*.subtype' => ['required', Rule::in(['cash', 'bank', 'accounts_receivable', 'accounts_payable', 'input_tax', 'output_tax', 'other'])],
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
        return array_values($this->validated('accounts'));
    }

    public function companyContext(): ?string
    {
        $company = $this->validated('company');

        return is_string($company) && $company !== '' ? $company : null;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'The accounting OCR request is invalid.',
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }
}
