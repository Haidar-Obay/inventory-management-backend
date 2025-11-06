<?php

namespace App\Http\Requests\Trade;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTradeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $trade = $this->route('trade');

        return [
            'code' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('trades', 'code')->ignore($trade ? $trade->id : null),
            ],
            'name' => 'sometimes|string|max:255',
            'active' => 'boolean',
        ];
    }
}
