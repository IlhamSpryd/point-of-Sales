<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
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
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email,' . $this->user->id],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'role_id' => ['nullable', 'exists:roles,id'],
        ];

        if ($this->filled('password')) {
            $rules['password'] = ['confirmed', Password::defaults()];
        }

        return $rules;
    }
}
