<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // table_number DIHAPUS: nomor meja sekarang diambil otomatis dari
            // session (hasil scan QR), bukan input manual pelanggan.
            'payment_method' => ['required', Rule::in(['qris', 'ewallet', 'cash', 'card'])],
            'customer_phone' => ['nullable', 'string', 'max:20'],
        ];
    }
}
