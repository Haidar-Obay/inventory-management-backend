<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $routeService = request()->route('service');
        $serviceId = is_object($routeService) ? ($routeService->id ?? null) : $routeService;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('services', 'name')->ignore($serviceId)],
            'service_category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'sub_department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'cnss_code' => ['nullable', 'string', 'max:100'],
            'result_after_days' => ['nullable', 'integer', 'min:0'],
            'needs_specialist' => ['boolean'],
            'specialist_ids' => ['nullable', 'array', 'required_if:needs_specialist,true'],
            'specialist_ids.*' => ['integer', 'exists:specialists,id'],
            'duration_minutes' => ['nullable', 'integer', 'min:0'],
            'normal_price' => ['nullable', 'numeric', 'min:0'],
            'vip_price' => ['nullable', 'numeric', 'min:0'],
            'price_in_group' => ['nullable', 'numeric', 'min:0'],
            'event_pricing' => ['boolean'],
            'price_calculated_by_hour' => ['boolean'],
            'hour_price' => ['nullable', 'numeric', 'min:0', 'required_if:price_calculated_by_hour,true'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0'],
            'image' => ['nullable', 'string', 'max:2048'],
            'service_color' => ['nullable', 'string', 'max:50'],
            'service_sex' => ['nullable', Rule::in(['male', 'female', 'both'])],
            'active' => ['boolean'],
        ];
    }
}


