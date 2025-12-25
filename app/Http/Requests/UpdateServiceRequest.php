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

    /**
     * Override the validation behavior to handle FormData with 'data' field
     */
    protected function prepareForValidation()
    {
        // If this is FormData with a 'data' field, decode it and merge into request
        if ($this->has('data')) {
            $data = json_decode($this->input('data'), true);
            if (is_array($data)) {
                // If files are being uploaded via 'attachments' field, store attachments metadata
                // in a separate field before unsetting it, so the controller can access it
                if (($this->hasFile('attachments') || $this->hasFile('attachments.*')) && isset($data['attachments'])) {
                    $this->merge(['_attachment_metadata' => $data['attachments']]);
                    unset($data['attachments']);
                }
                $this->merge($data);
            }
        }
    }

    public function rules(): array
    {
        $routeService = request()->route('service');
        $serviceId = is_object($routeService) ? ($routeService->id ?? null) : $routeService;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('services', 'name')->ignore($serviceId)],
            'service_category_id' => ['nullable', 'integer', 'exists:service_categories,id'],
            'result_after_days' => ['nullable', 'integer', 'min:0'],
            'needs_specialist' => ['boolean'],
            'needs_asset' => [
                'boolean',
                function ($attribute, $value, $fail) {
                    // If needs_asset is true, hour_capacity must be null
                    if ($value == true && $this->input('hour_capacity') !== null) {
                        $fail('Services that need assets cannot have hour capacity. Please clear the hour capacity field first.');
                    }
                },
            ],
            'specialist_ids' => ['nullable', 'array'],
            'specialist_ids.*' => ['integer', 'exists:specialists,id'],
            'asset_ids' => ['nullable', 'array'],
            'asset_ids.*' => ['integer', 'exists:assets,id'],
            'duration_minutes' => ['nullable', 'integer', 'min:0'],
            'hour_capacity' => [
                'nullable',
                'integer',
                'min:0',
                function ($attribute, $value, $fail) {
                    // If hour_capacity is set, needs_asset must be false
                    if ($value !== null && $this->input('needs_asset') == true) {
                        $fail('Hour capacity cannot be set when the service needs assets. Please uncheck "Needs Asset" first.');
                    }
                },
            ],
            'normal_price' => ['nullable', 'numeric', 'min:0'],
            'vip_price' => ['nullable', 'numeric', 'min:0'],
            'price_in_group' => ['nullable', 'numeric', 'min:0'],
            'price_calculated_by_hour' => ['boolean'],
            'hour_price' => ['nullable', 'numeric', 'min:0', 'required_if:price_calculated_by_hour,true'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'birthday_price' => ['nullable', 'numeric', 'min:0'],
            'wedding_price' => ['nullable', 'numeric', 'min:0'],
            'service_color' => ['nullable', 'string', 'max:50'],
            'service_sex' => ['nullable', Rule::in(['male', 'female', 'both'])],
            'active' => ['boolean'],
        ];
    }
}
