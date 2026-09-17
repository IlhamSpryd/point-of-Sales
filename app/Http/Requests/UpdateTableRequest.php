<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'table_number' => ['required', 'string', 'max:10', 'unique:tables,table_number,'.$this->table->id],
            'status' => ['required', 'in:active,inactive'],
        ];
    }
}
