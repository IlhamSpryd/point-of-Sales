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
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            // Hanya izinkan metode pembayaran yang benar-benar didukung sistem,
            // agar tidak ada nilai sembarangan yang lolos ke TransactionService.
            'payment_method' => ['required', 'string', Rule::in(['cash', 'qris', 'ewallet'])],
            'cash_received' => 'nullable|numeric|min:0',
        ];
    }
}
