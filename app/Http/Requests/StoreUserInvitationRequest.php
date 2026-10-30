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
            'name.required' => 'Employee name is required.',
            'email.required' => 'Employee email is required.',
            'email.email' => 'Invalid email format.',
            'role_id.required' => 'Role is required.',
            'role_id.exists' => 'The selected role is invalid for this business.',
            'store_ids.required' => 'Select at least one assigned branch.',
            'store_ids.min' => 'Select at least one assigned branch.',
            'store_ids.*.exists' => 'The selected branch is invalid for this business.',
        ];
    }
}
