<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Context\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaxClassRequest extends FormRequest
{
    public function authorize(): bool
    {
        $roleName = $this->user()?->role?->name;

        return in_array($roleName, ['Owner', 'Manager', 'Inventory'], true);
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->requireTenantId();

        return [
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('tax_classes', 'code')->where('tenant_id', $tenantId),
            ],
            'name' => 'required|string|max:120',
            'rate' => 'required|numeric|between:0,100',
            'is_active' => 'nullable|boolean',
        ];
    }
}
