<?php

namespace App\Http\Requests\Item;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUOMPivotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'operation' => 'required|string|in:multiply,divide',
            'conversion' => 'required|numeric|gt:0',
            'barcodes' => 'nullable|array',
            'barcodes.*' => 'string|max:255',
            'price_1' => 'nullable|numeric|min:0',
            'price_2' => 'nullable|numeric|min:0',
            'price_3' => 'nullable|numeric|min:0',
            'price_4' => 'nullable|numeric|min:0',
            'price_5' => 'nullable|numeric|min:0',
            'price_6' => 'nullable|numeric|min:0',
            'gross_volume' => 'nullable|numeric|min:0',
            'gross_weight' => 'nullable|numeric|min:0',
            'net_volume' => 'nullable|numeric|min:0',
            'net_weight' => 'nullable|numeric|min:0',
        ];
    }
}
