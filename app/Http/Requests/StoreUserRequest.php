<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Form Request: Verifikasi ketat pembuatan akun User (Staff/Admin) baru sebelum masuk ke database.
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role_id' => ['required', 'exists:roles,id'],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'pin_code' => ['nullable', 'string', 'digits_between:4,6'],
            'join_date' => ['nullable', 'date'],
            'is_active' => ['boolean'],
        ];
    }
}
