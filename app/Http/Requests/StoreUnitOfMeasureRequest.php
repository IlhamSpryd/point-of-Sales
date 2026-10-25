<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Context\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUnitOfMeasureRequest extends FormRequest
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
                'max:32',
                Rule::unique('units_of_measure', 'code')->where('tenant_id', $tenantId),
            ],
            'name' => 'required|string|max:100',
            'precision_scale' => 'nullable|integer|between:0,4',
            'is_active' => 'nullable|boolean',
        ];
    }
}
