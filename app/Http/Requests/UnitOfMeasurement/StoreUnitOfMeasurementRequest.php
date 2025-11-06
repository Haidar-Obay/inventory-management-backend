<?php

namespace App\Http\Requests\UnitOfMeasurement;

use Illuminate\Foundation\Http\FormRequest;

class StoreUnitOfMeasurementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'unit_group_id' => 'required|integer|exists:unit_groups,id',
            'operation' => 'required|string|in:multiply,divide',
            'conversion' => 'required|numeric|min:0.0001',
        ];
    }
}
