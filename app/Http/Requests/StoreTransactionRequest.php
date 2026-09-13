<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mengembalikan konsistensi pola Form Request yang sudah dipakai di seluruh modul lain proyek
 * alih-alih meletakkan validasi mentah (raw validation) di dalam Controller.
 */
class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => 'required|array|min:1',
            'items.*.product_id' => [
                'required',
                // Validasi ganda: produk harus ADA di database DAN berstatus is_active = true.
                // Ini mencegah produk yang sudah dinonaktifkan Admin tetap bisa "disundul"
                // langsung lewat request API, meskipun sudah disembunyikan dari UI kasir.
                Rule::exists('products', 'id')->where(function ($query) {
                    $query->where('is_active', true);
                }),
            ],
            'items.*.quantity' => 'required|integer|min:1',
            // Hanya izinkan metode pembayaran yang benar-benar didukung sistem,
            // agar tidak ada nilai sembarangan yang lolos ke TransactionService.
            'payment_method' => ['required', 'string', Rule::in(['cash', 'qris', 'ewallet'])],
            'cash_received' => 'nullable|numeric|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'items.*.product_id.exists' => 'Salah satu produk di keranjang sudah tidak tersedia atau dinonaktifkan. Silakan muat ulang halaman.',
        ];
    }
}
