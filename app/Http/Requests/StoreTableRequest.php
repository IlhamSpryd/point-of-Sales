<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'table_name' => ['required', 'string', 'max:100', 'unique:tables,table_name'],
            'capacity' => ['required', 'integer', 'min:1'],
            'area' => ['required', 'string'],
            'is_active' => ['boolean'],
            'operational_status' => ['required', 'in:available,occupied,cleaning,reserved'],
        ];
    }
}
