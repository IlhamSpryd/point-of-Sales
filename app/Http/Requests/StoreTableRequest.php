<?php

namespace App\Http\Requests;

use App\Services\Context\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Nama meja unik PER TENANT (bukan global).
            'table_name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('tables', 'table_name')
                    ->where('tenant_id', app(TenantContext::class)->requireTenantId()),
            ],
            'capacity' => ['required', 'integer', 'min:1'],
            'area' => ['required', 'string'],
            'is_active' => ['boolean'],
            'operational_status' => ['required', 'in:available,occupied,cleaning,reserved'],
        ];
    }
}
