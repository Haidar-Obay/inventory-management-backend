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
            'title' => 'nullable|string|max:50',
            'name' => 'required|string|max:255',
            'work_phone' => 'nullable|string|max:20',
            'mobile' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'position' => 'nullable|string|max:255',
            'extension' => 'nullable|string|max:20',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Contact name is required.',
            'name.max' => 'Contact name cannot exceed 255 characters.',
            'title.max' => 'Title cannot exceed 50 characters.',
            'work_phone.max' => 'Work phone cannot exceed 20 characters.',
            'mobile.max' => 'Mobile phone cannot exceed 20 characters.',
            'email.email' => 'Please provide a valid email address.',
            'email.max' => 'Email cannot exceed 255 characters.',
            'position.max' => 'Position cannot exceed 255 characters.',
            'extension.max' => 'Extension cannot exceed 20 characters.',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array
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
