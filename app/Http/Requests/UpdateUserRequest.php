<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
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
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email,' . $this->user->id],
            'role_id' => ['required', 'exists:roles,id'],
        ];

        if ($this->filled('password')) {
            $rules['password'] = ['confirmed', Password::defaults()];
        }

        return $rules;
    }
}
