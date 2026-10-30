<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Device;
use App\Services\Context\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Device::class) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->requireTenantId();

        return [
            'store_id' => [
                'required',
                'integer',
                Rule::exists('stores', 'id')->where('tenant_id', $tenantId),
            ],
            'device_name' => ['required', 'string', 'max:100'],
            'device_type' => ['required', 'string', Rule::in([
                Device::TYPE_POS_TERMINAL,
                Device::TYPE_KDS_DISPLAY,
                Device::TYPE_WAITER_TABLET,
            ])],
            'device_code' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'store_id.required' => 'Assigned branch is required.',
            'store_id.exists' => 'The selected branch is invalid or not associated with your business.',
            'device_name.required' => 'Device name is required.',
            'device_type.required' => 'Device type is required.',
            'device_type.in' => 'Selected device type is invalid.',
        ];
    }
}
