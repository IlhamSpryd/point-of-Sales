<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi input saat pelanggan menekan tombol "Tambah ke Keranjang"
 * di modal pilihan varian.
 */
class AddToCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Publik, tidak perlu login — sesuai konsep self-order.
        return true;
    }

    public function rules(): array
    {
        return [
            // PATCH FOR S-12: hanya produk aktif dan tidak soft-deleted.
            'product_id' => ['required', Rule::exists('products', 'id')
                ->where(fn ($q) => $q->where('is_active', true)->whereNull('deleted_at'))],
            'qty' => ['required', 'integer', 'min:1', 'max:20'],
            // modifier_ids boleh kosong (array kosong) jika produk tidak punya varian sama sekali,
            // tapi kalau ada isinya, setiap ID wajib benar-benar ada dan aktif.
            'modifier_ids' => ['nullable', 'array'],
            // PATCH FOR S-12: hanya modifier aktif dan tidak soft-deleted.
            'modifier_ids.*' => ['integer', Rule::exists('modifiers', 'id')
                ->where(fn ($q) => $q->where('is_active', true)->whereNull('deleted_at'))],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
