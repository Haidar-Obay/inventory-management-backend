<?php

namespace App\Http\Requests\CustomerContact;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerContactRequest extends FormRequest
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
            'customer_id' => 'sometimes|exists:customers,id',
            'title' => 'sometimes|nullable|string|max:50',
            'name' => 'sometimes|required|string|max:255',
            'work_phone' => 'sometimes|nullable|string|max:20',
            'mobile' => 'sometimes|nullable|string|max:20',
            'position' => 'sometimes|nullable|string|max:255',
            'extension' => 'sometimes|nullable|string|max:20',
            'is_primary' => 'sometimes|boolean',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'customer_id.exists' => 'The selected customer does not exist.',
            'name.required' => 'Contact name is required.',
            'name.max' => 'Contact name cannot exceed 255 characters.',
            'title.max' => 'Title cannot exceed 50 characters.',
            'work_phone.max' => 'Work phone cannot exceed 20 characters.',
            'mobile.max' => 'Mobile phone cannot exceed 20 characters.',
            'position.max' => 'Position cannot exceed 255 characters.',
            'extension.max' => 'Extension cannot exceed 20 characters.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'name' => 'contact name',
            'work_phone' => 'work phone',
            'mobile' => 'mobile phone',
            'position' => 'position/role',
        ];
    }
}
