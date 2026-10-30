<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Device;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $device = $this->route('device');

        return $device && $this->user()?->can('update', $device);
    }

    public function rules(): array
    {
        return [
            'device_name' => ['required', 'string', 'max:100'],
            'device_type' => ['required', 'string', Rule::in([
                Device::TYPE_POS_TERMINAL,
                Device::TYPE_KDS_DISPLAY,
                Device::TYPE_WAITER_TABLET,
            ])],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'device_name.required' => 'Device name is required.',
            'device_type.required' => 'Device type is required.',
            'device_type.in' => 'Selected device type is invalid.',
        ];
    }
}
