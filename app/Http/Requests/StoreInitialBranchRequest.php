<?php

namespace App\Http\Requests;

use App\Models\Store;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInitialBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        return $user->role?->name === 'Owner' || (bool) ($user->role->is_admin ?? false);
    }

    public function rules(): array
    {
        $tenantId = $this->user()?->tenant_id;
        $initialStoreId = Store::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->value('id');

        return [
            'store_name' => ['required', 'string', 'min:2', 'max:120'],
            'store_code' => [
                'required',
                'string',
                'min:2',
                'max:20',
                'regex:/^[A-Z0-9\-]+$/i',
                Rule::unique('stores', 'code')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId))
                    ->ignore($initialStoreId),
            ],
            'store_timezone' => ['required', 'string', 'timezone:all'],
            'business_date_cutoff' => ['required', 'string', 'date_format:H:i'],
            'default_channel' => [
                'required',
                'string',
                Rule::in(['dine_in', 'takeaway', 'delivery']),
            ],
            'store_address' => ['nullable', 'string', 'max:500'],
            'store_phone' => ['nullable', 'string', 'max:32'],
        ];
    }

    public function messages(): array
    {
        return [
            'store_name.required' => 'Primary branch name is required.',
            'store_code.required' => 'Branch code is required.',
            'store_code.regex' => 'Branch code may only contain uppercase letters, numbers, and hyphens (e.g., MAIN-01).',
            'store_code.unique' => 'This branch code is already in use within your business.',
            'business_date_cutoff.required' => 'Daily business cutoff time is required.',
            'business_date_cutoff.date_format' => 'Cutoff time must use the HH:mm format (e.g., 00:00 or 04:00).',
            'default_channel.required' => 'Default sales channel is required.',
        ];
    }
}
