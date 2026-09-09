<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'category_id' => 'required|exists:categories,id',
            'name'        => 'required|string|max:255',
            'price'       => 'required|numeric|min:0',
            'stock'       => 'required|integer|min:0',
            'photo'       => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'sku'         => 'nullable|string|unique:products,sku,' . $this->product->id,
            'barcode'     => 'nullable|string|unique:products,barcode,' . $this->product->id,
            'description' => 'nullable|string',
            'cost_price'  => 'nullable|numeric|min:0',
        ];
    }
}
