<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

class BulkUpdateProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Product::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['required', 'integer'],
            'action' => ['required', 'string', 'in:update_status,update_category,update_price,update_assortment'],
            // Conditional rules based on action
            'status' => ['required_if:action,update_status', 'nullable', 'string', 'in:draft,active,inactive,discontinued'],
            'category_id' => ['required_if:action,update_category', 'nullable', 'integer'],
            'price_value' => ['required_if:action,update_price', 'nullable', 'numeric'],
            'price_mode' => ['required_if:action,update_price', 'nullable', 'string', 'in:fixed,percentage,delta'],
            'store_id' => ['required_if:action,update_assortment', 'nullable', 'integer'],
            'is_available' => ['nullable', 'boolean'],
            'store_price_override' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
