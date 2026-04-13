<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProcessIcOcrRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            'image' => [
                'required',
                'file',
                'max:'.(int) config('ocr.max_file_size_kb', 5120),
            ],
        ];
    }
}
