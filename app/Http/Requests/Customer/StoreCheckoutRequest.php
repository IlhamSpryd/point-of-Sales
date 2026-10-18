<?php

declare(strict_types=1);

namespace App\Http\Requests\Customer;

use App\Enums\PaymentMethod;
use App\Models\Table;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) session('current_table_id');
    }

    public function rules(): array
    {
        return [
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'customer_name' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
            'customer_notes' => ['nullable', 'string', 'max:500'],
            '_idempotency_key' => ['nullable', 'string', 'max:64'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $tableId = session('current_table_id');
            if (! $tableId) {
                $validator->errors()->add('table', 'Sesi meja tidak ditemukan, silakan scan ulang QR Code.');

                return;
            }

            $tenantId = app(TenantContext::class)->getTenantId();
            if ($tenantId !== null) {
                $table = Table::withoutGlobalScopes()
                    ->where('id', $tableId)
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true)
                    ->first();

                if (! $table) {
                    $validator->errors()->add('table', 'Meja tidak valid atau tidak aktif pada tenant ini.');
                }
            }
        });
    }
}
