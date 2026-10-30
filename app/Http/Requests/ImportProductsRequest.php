<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

class ImportProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Product::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:csv,txt',
                'max:2048', // Maksimal 2 MB
            ],
            'strict_mode' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'A CSV file must be uploaded.',
            'file.mimes' => 'The file must be a CSV format document (.csv or .txt).',
            'file.max' => 'The file size must not exceed 2 MB.',
        ];
    }
}
