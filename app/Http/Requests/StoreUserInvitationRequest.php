<?php

namespace App\Http\Requests;

use App\Models\Role;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        $role = $user->role;

        return $role !== null && ($role->name === 'Owner' || (bool) $role->is_admin);
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->requireTenantId();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'role_id' => [
                'required',
                'integer',
                Rule::exists('roles', 'id')->where('tenant_id', $tenantId),
                function (string $attribute, mixed $value, \Closure $fail) use ($tenantId) {
                    $role = Role::withoutTenantScope()->where('id', $value)->where('tenant_id', $tenantId)->first();
                    if ($role && strtolower($role->name) === 'owner') {
                        $fail('Tidak dapat mengundang staf dengan peran Owner.');
                    }
                },
            ],
            'store_ids' => ['required', 'array', 'min:1'],
            'store_ids.*' => [
                'integer',
                Rule::exists('stores', 'id')->where('tenant_id', $tenantId),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama karyawan wajib diisi.',
            'email.required' => 'Email karyawan wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'role_id.required' => 'Peran wajib dipilih.',
            'role_id.exists' => 'Peran yang dipilih tidak valid untuk bisnis ini.',
            'store_ids.required' => 'Pilih minimal satu cabang penugasan.',
            'store_ids.min' => 'Pilih minimal satu cabang penugasan.',
            'store_ids.*.exists' => 'Cabang yang dipilih tidak valid untuk bisnis ini.',
        ];
    }
}
