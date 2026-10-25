<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        return $user->can('create', Order::class);
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $storeId = app(TenantContext::class)->getStoreId();

        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => [
                'required',
                Rule::exists('products', 'id')->where(function ($query) use ($tenantId) {
                    $query->where('is_active', true)
                        ->where('tenant_id', $tenantId);
                }),
            ],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.extra_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.options' => ['nullable', 'array'],
            'items.*.options.*.modifier_id' => [
                'required_with:items.*.options',
                Rule::exists('modifiers', 'id')->where(function ($q) use ($tenantId) {
                    $q->where('is_active', true)
                        ->where('tenant_id', $tenantId);
                }),
            ],
            'items.*.options.*.extra_price' => ['nullable', 'numeric', 'min:0'],

            'payment_method' => [
                'required_without:payments',
                'nullable',
                Rule::enum(PaymentMethod::class),
            ],
            'cash_received' => ['nullable', 'numeric', 'min:0'],

            'payments' => ['nullable', 'array', 'min:1'],
            'payments.*.method' => [
                'required_with:payments',
                Rule::enum(PaymentMethod::class),
            ],
            'payments.*.amount' => ['required_with:payments', 'integer', 'min:1'],
            'payments.*.reference_number' => ['nullable', 'string', 'max:100'],
            'payments.*.idempotency_key' => ['nullable', 'string', 'max:100'],

            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where(function ($q) use ($tenantId) {
                    $q->where('is_active', true)
                        ->where('tenant_id', $tenantId);
                }),
            ],
            'discount_id' => [
                'nullable',
                'integer',
                Rule::exists('discounts', 'id')->where(function ($q) use ($tenantId, $storeId) {
                    $q->where('is_active', true)
                        ->where('tenant_id', $tenantId)
                        ->where(function ($sub) use ($storeId) {
                            $sub->whereNull('store_id');
                            if ($storeId !== null) {
                                $sub->orWhere('store_id', $storeId);
                            }
                        });
                }),
            ],
            'table_id' => [
                'nullable',
                'integer',
                Rule::exists('tables', 'id')->where(function ($q) use ($tenantId, $storeId) {
                    $q->where('tenant_id', $tenantId)
                        ->where('is_active', true);
                    if ($storeId !== null) {
                        $q->where('store_id', $storeId);
                    }
                }),
            ],
            'shift_id' => [
                'nullable',
                'integer',
                Rule::exists('shifts', 'id')->where(function ($q) use ($tenantId, $storeId) {
                    $q->where('tenant_id', $tenantId)
                        ->where('status', 'open');
                    if ($storeId !== null) {
                        $q->where('store_id', $storeId);
                    }
                }),
            ],
            'order_type' => ['nullable', 'string', Rule::in(array_map(fn ($c) => $c->value, OrderType::cases()))],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.*.product_id.exists' => 'Salah satu produk di keranjang sudah tidak tersedia atau dinonaktifkan. Silakan muat ulang halaman.',
        ];
    }
}
