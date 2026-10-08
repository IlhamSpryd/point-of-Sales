<?php

namespace App\Http\Requests;

use App\Services\Context\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Form Request: Benteng penyaring data pembaruan detail akun User dan bypass password kosong.
 */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email,'.$this->user->id], // Email unik GLOBAL
            // role_id HARUS milik tenant pada konteks aktif — role lintas tenant ditolak.
            'role_id' => ['required', Rule::exists('roles', 'id')->where('tenant_id', app(TenantContext::class)->requireTenantId())],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'pin_code' => ['nullable', 'string', 'digits_between:4,6'],
            'join_date' => ['nullable', 'date'],
            'is_active' => ['boolean'],
        ];

        if ($this->filled('password')) {
            $rules['password'] = ['confirmed', Password::defaults()];
        }

        return $rules;
    }
}
