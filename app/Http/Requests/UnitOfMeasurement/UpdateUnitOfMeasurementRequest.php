<?php

namespace App\Http\Requests\UnitOfMeasurement;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUnitOfMeasurementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'unit_group_id' => 'sometimes|integer|exists:unit_groups,id',
            'operation' => 'sometimes|string|in:multiply,divide',
            'conversion' => 'sometimes|numeric|min:0.0001',
        ];
    }
}
