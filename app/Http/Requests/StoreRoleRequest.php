<?php

namespace App\Http\Requests;

use App\Services\Context\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Validator: Pengecekan pendaftaran Peran (Role) baru dalam arsitektur hak akses.
 */
class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Nama role unik PER TENANT (bukan global) — dua tenant boleh sama-sama punya role "Kasir".
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->where('tenant_id', app(TenantContext::class)->requireTenantId())],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['nullable', 'array'],
            'is_active' => 'boolean',
        ];
    }
}
