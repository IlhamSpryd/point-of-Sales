<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\UnitOfMeasure;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUnitOfMeasureRequest extends FormRequest
{
    public function authorize(): bool
    {
        $roleName = $this->user()?->role?->name;

        return in_array($roleName, ['Owner', 'Manager', 'Inventory'], true);
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->requireTenantId();
        $uomId = $this->route('units_of_measure') instanceof UnitOfMeasure
            ? $this->route('units_of_measure')->id
            : ($this->route('unit_of_measure') instanceof UnitOfMeasure
                ? $this->route('unit_of_measure')->id
                : ($this->route('units_of_measure') ?? $this->route('unit_of_measure')));

        return [
            'code' => [
                'required',
                'string',
                'max:32',
                Rule::unique('units_of_measure', 'code')->where('tenant_id', $tenantId)->ignore($uomId),
            ],
            'name' => 'required|string|max:100',
            'precision_scale' => 'nullable|integer|between:0,4',
            'is_active' => 'nullable|boolean',
        ];
    }
}
