<?php

namespace App\Http\Requests;

use App\Services\StatutoryOcr\StatutoryOcrPipeline;
use App\Support\OcrDocumentMime;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ProcessStatutoryReceiptOcrRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'scheme' => strtolower((string) ($this->input('scheme') ?: $this->route('scheme'))),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKb = (int) config('ocr.statutory_max_file_size_kb', 10240);
        $scheme = $this->expectedScheme();

        return [
            'image' => ['required', 'file', 'max:'.$maxKb],
            'scheme' => ['required', 'string', 'in:'.$scheme],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'image.required' => 'The image field is required.',
            'image.file' => 'The image must be a PDF, JPG, PNG or WebP file.',
            'image.max' => 'The image must not be greater than 10 MB.',
            'scheme.required' => 'The scheme field is required.',
            'scheme.in' => 'The scheme must match the endpoint.',
        ];
    }

    public function expectedScheme(): string
    {
        return strtolower((string) $this->route('scheme'));
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'The uploaded receipt is invalid.',
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $file = $this->file('image');
            if ($file === null) {
                return;
            }

            $realPath = $file->getRealPath();
            if ($realPath === false) {
                $validator->errors()->add('image', 'The image must be a PDF, JPG, PNG or WebP file.');

                return;
            }

            $content = @file_get_contents($realPath);
            if ($content === false || ! OcrDocumentMime::isAllowed($content, [
                'application/pdf',
                'image/jpeg',
                'image/png',
                'image/webp',
            ])) {
                $validator->errors()->add('image', 'The image must be a PDF, JPG, PNG or WebP file.');
            }

            if (! in_array($this->expectedScheme(), StatutoryOcrPipeline::SCHEMES, true)) {
                $validator->errors()->add('scheme', 'The scheme must match the endpoint.');
            }
        });
    }
}
