<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:services,name'],
            'service_category_id' => ['nullable', 'integer', 'exists:service_categories,id'],
            'result_after_days' => ['nullable', 'integer', 'min:0'],
            'needs_specialist' => ['boolean'],
            'needs_asset' => ['boolean'],
            'specialist_ids' => ['nullable', 'array'],
            'specialist_ids.*' => ['integer', 'exists:specialists,id'],
            'asset_ids' => ['nullable', 'array'],
            'asset_ids.*' => ['integer', 'exists:assets,id'],
            'duration_minutes' => ['nullable', 'integer', 'min:0'],
            'normal_price' => ['nullable', 'numeric', 'min:0'],
            'vip_price' => ['nullable', 'numeric', 'min:0'],
            'price_in_group' => ['nullable', 'numeric', 'min:0'],
            'price_calculated_by_hour' => ['boolean'],
            'hour_price' => ['nullable', 'numeric', 'min:0', 'required_if:price_calculated_by_hour,true'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'birthday_price' => ['nullable', 'numeric', 'min:0'],
            'wedding_price' => ['nullable', 'numeric', 'min:0'],
            'image' => ['nullable'],
            'service_color' => ['nullable', 'string', 'max:50'],
            'service_sex' => ['nullable', Rule::in(['male', 'female', 'both'])],
            'active' => ['boolean'],
        ];
    }
}
