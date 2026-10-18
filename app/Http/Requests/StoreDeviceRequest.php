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
            'store_id.required' => 'Cabang penempatan perangkat wajib dipilih.',
            'store_id.exists' => 'Cabang yang dipilih tidak valid atau tidak terdaftar pada bisnis Anda.',
            'device_name.required' => 'Nama perangkat kasir wajib diisi.',
            'device_type.required' => 'Tipe perangkat wajib dipilih.',
            'device_type.in' => 'Tipe perangkat tidak valid.',
        ];
    }
}
