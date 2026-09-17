<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

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
            'product_id' => ['required', 'exists:products,id'],
            'qty' => ['required', 'integer', 'min:1', 'max:20'],
            // modifier_ids boleh kosong (array kosong) jika produk tidak punya varian sama sekali,
            // tapi kalau ada isinya, setiap ID wajib benar-benar ada di tabel modifiers.
            'modifier_ids' => ['nullable', 'array'],
            'modifier_ids.*' => ['integer', 'exists:modifiers,id'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
