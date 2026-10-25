<?php

namespace App\Http\Requests;

use App\Models\Table;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        $roleName = $this->user()?->role?->name;

        return in_array($roleName, ['Owner', 'Manager'], true);
    }

    public function rules(): array
    {
        $table = $this->route('table');
        $tableId = $table instanceof Table ? $table->id : ($this->table ? $this->table->id : null);
        $tenantId = app(TenantContext::class)->requireTenantId();
        $storeId = ($table instanceof Table ? $table->store_id : null) ?: app(TenantContext::class)->getStoreId();

        $tableUniqueRule = Rule::unique('tables', 'table_name')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at');

        if ($storeId !== null) {
            $tableUniqueRule->where('store_id', $storeId);
        }

        if ($tableId !== null) {
            $tableUniqueRule->ignore($tableId);
        }

        return [
            // Nama meja unik PER CABANG (tenant_id, store_id), mengecualikan baris yang sedang diedit dan mengabaikan baris soft-deleted.
            'table_name' => [
                'required',
                'string',
                'max:100',
                $tableUniqueRule,
            ],
            'capacity' => ['required', 'integer', 'min:1'],
            'area' => ['required', 'string'],
            'is_active' => ['boolean'],
            'operational_status' => ['required', 'in:available,occupied,cleaning,reserved,disabled'],
        ];
    }
}
