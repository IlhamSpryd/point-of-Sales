<?php

namespace App\Http\Requests;

use App\Services\Context\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Validator: Pengawal validasi untuk pembaruan atribut dari suatu Jabatan (Role).
 */
class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role?->name === 'Owner' || (bool) ($this->user()?->role?->is_admin ?? false);
    }

    public function rules(): array
    {
        return [
            // Nama role unik PER TENANT (bukan global) — dua tenant boleh sama-sama punya role "Kasir".
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->where('tenant_id', app(TenantContext::class)->requireTenantId())->ignore($this->role->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['nullable', 'array'],
            'dashboard_archetype' => ['nullable', 'string', Rule::in(['executive', 'branch_ops', 'cashier_workspace', 'inventory_supply', 'finance_ledger', 'sales_b2b', 'kds_floor'])],
            'is_active' => ['boolean'],
        ];
    }
}
