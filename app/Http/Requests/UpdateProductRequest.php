<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request penanggung jawab pembatasan input untuk suntingan (Update) Data Produk.
 */
class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => 'required|exists:categories,id',
            'product_name' => 'required|string|max:255',
            'product_price' => 'required|numeric|min:0',
            'stock' => 'required|integer|min:0',
            'product_photo' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'product_description' => 'nullable|string',
            'is_active' => 'nullable|boolean',

            // BOM / Ingredients validation
            'ingredients' => 'nullable|array',
            'ingredients.*.id' => 'required_with:ingredients|exists:ingredients,id',
            'ingredients.*.quantity' => 'required_with:ingredients|numeric|min:0.0001',

            // Modifier Groups
            'modifier_groups' => 'nullable|array',
            'modifier_groups.*' => 'exists:modifier_groups,id',
        ];
    }
}
