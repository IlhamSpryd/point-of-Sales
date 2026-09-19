<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Validator: Pengawal validasi untuk pembaruan atribut dari suatu Jabatan (Role).
 */
class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255|unique:roles,name,'.$this->role->id,
            'description' => 'nullable|string|max:1000',
            'permissions' => 'nullable|array',
            'is_active' => 'boolean',
        ];
    }
}
