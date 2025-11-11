<?php

namespace App\Http\Requests\Item;

use Illuminate\Foundation\Http\FormRequest;

class AttachUOMRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'unit_of_measurements' => 'required|array|min:1',
            'unit_of_measurements.*.unit_of_measurement_id' => 'required|integer|exists:unit_of_measurements,id',
            'unit_of_measurements.*.operation' => 'required|string|in:multiply,divide',
            'unit_of_measurements.*.conversion' => 'required|numeric|gt:0',
            'unit_of_measurements.*.barcodes' => 'nullable|array',
            'unit_of_measurements.*.barcodes.*' => 'string|max:255',
            'unit_of_measurements.*.price_1' => 'nullable|numeric|min:0',
            'unit_of_measurements.*.price_2' => 'nullable|numeric|min:0',
            'unit_of_measurements.*.price_3' => 'nullable|numeric|min:0',
            'unit_of_measurements.*.price_4' => 'nullable|numeric|min:0',
            'unit_of_measurements.*.price_5' => 'nullable|numeric|min:0',
            'unit_of_measurements.*.price_6' => 'nullable|numeric|min:0',
            'unit_of_measurements.*.gross_volume' => 'nullable|numeric|min:0',
            'unit_of_measurements.*.gross_weight' => 'nullable|numeric|min:0',
            'unit_of_measurements.*.net_volume' => 'nullable|numeric|min:0',
            'unit_of_measurements.*.net_weight' => 'nullable|numeric|min:0',
        ];
    }
}
