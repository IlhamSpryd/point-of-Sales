<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Brand;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        $roleName = $this->user()?->role?->name;

        return in_array($roleName, ['Owner', 'Manager', 'Inventory'], true);
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $brandId = $this->route('brand') instanceof Brand
            ? $this->route('brand')->id
            : $this->route('brand');

        return [
            'code' => [
                'required',
                'string',
                'max:64',
                Rule::unique('brands', 'code')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')
                    ->ignore($brandId),
            ],
            'name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('brands', 'name')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at')
                    ->ignore($brandId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
