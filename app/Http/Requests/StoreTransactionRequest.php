<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
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
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.custom_price' => ['nullable', 'numeric', 'min:0'],
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
            'items.*.product_id.exists' => 'One of the items in your cart is no longer available or has been deactivated. Please refresh the page.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $user = $this->user();
            $items = $this->input('items', []);
            if (! is_array($items)) {
                return;
            }

            foreach ($items as $index => $item) {
                if (array_key_exists('custom_price', $item) && $item['custom_price'] !== null && $item['custom_price'] !== '') {
                    $productId = $item['product_id'] ?? null;
                    $variantId = $item['variant_id'] ?? null;

                    $product = Product::find($productId);
                    $variant = $variantId ? ProductVariant::find($variantId) : null;

                    $isOpenPrice = (bool) ($variant?->is_open_price ?? $product?->is_open_price ?? false);

                    if (! $isOpenPrice) {
                        $validator->errors()->add("items.{$index}.custom_price", 'Produk ini tidak mendukung harga manual (open price).');
                        continue;
                    }

                    $hasPermission = $user && ($user->hasPermission('pos.price.open_entry') || ($user->role?->is_admin ?? false) || ($user->role?->name === 'Owner'));
                    if (! $hasPermission) {
                        $validator->errors()->add("items.{$index}.custom_price", 'Anda tidak memiliki hak akses untuk memasukkan harga terbuka (open price).');
                    }
                }
            }
        });
    }
}
