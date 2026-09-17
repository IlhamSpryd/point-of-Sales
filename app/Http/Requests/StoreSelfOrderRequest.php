<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSelfOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // publik, tanpa auth -- otorisasi cukup lewat validitas token meja
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:20'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
            // modifier_ids: array polos ID mentah dari client -- INI TIDAK
            // dipercaya begitu saja, akan divalidasi ULANG di TransactionService
            // terhadap aturan modifier_groups (required/selection_type).
            'items.*.modifier_ids' => ['nullable', 'array'],
            'items.*.modifier_ids.*' => ['integer', 'exists:modifiers,id'],
        ];
    }
}
