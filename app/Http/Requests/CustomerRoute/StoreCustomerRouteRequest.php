<?php

namespace App\Http\Requests\CustomerRoute;

use App\Models\Salesman;
use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRouteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_id' => 'required|exists:customers,id',
            'salesman_id' => [
                'required',
                'exists:salesmen,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $salesman = Salesman::find($value);
                        if ($salesman && ! $salesman->active) {
                            $fail('The selected salesman is inactive and cannot be assigned to a customer route.');
                        }
                    }
                },
            ],
            'frequency' => 'required|in:weekly,biweekly,monthly',
            'day_value' => [
                'required',
                'integer',
                function ($attribute, $value, $fail) {
                    $frequency = $this->input('frequency');

                    if ($frequency === 'weekly' && ($value < 1 || $value > 7)) {
                        $fail('Day value must be between 1 and 7 for weekly frequency (1=Monday, 7=Sunday).');
                    }

                    if (in_array($frequency, ['biweekly', 'monthly']) && ($value < 1 || $value > 31)) {
                        $fail('Day value must be between 1 and 31 for biweekly and monthly frequency.');
                    }
                },
            ],
            'active' => 'boolean',
            'start_date' => 'nullable|date',
            'end_date' => [
                'nullable',
                'date',
                'after_or_equal:start_date',
            ],
            'notes' => 'nullable|string|max:1000',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'customer_id.required' => 'Customer ID is required.',
            'customer_id.exists' => 'The selected customer does not exist.',
            'salesman_id.required' => 'Salesman ID is required.',
            'salesman_id.exists' => 'The selected salesman does not exist.',
            'frequency.required' => 'Frequency is required.',
            'frequency.in' => 'Frequency must be weekly, biweekly, or monthly.',
            'day_value.required' => 'Day value is required.',
            'day_value.integer' => 'Day value must be a number.',
            'start_date.date' => 'Start date must be a valid date.',
            'end_date.date' => 'End date must be a valid date.',
            'end_date.after_or_equal' => 'End date must be after or equal to start date.',
            'notes.max' => 'Notes cannot exceed 1000 characters.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'customer_id' => 'customer',
            'salesman_id' => 'salesman',
            'day_value' => 'day value',
            'start_date' => 'start date',
            'end_date' => 'end date',
        ];
    }
}
