<?php

namespace App\Http\Requests\Item;

use Illuminate\Foundation\Http\FormRequest;

class DetachUOMRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'unit_of_measurement_ids' => 'required|array|min:1',
            'unit_of_measurement_ids.*' => 'integer|exists:unit_of_measurements,id',
        ];
    }
}
