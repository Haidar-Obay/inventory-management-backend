<?php

namespace App\Http\Requests\TableTemplate;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTableTemplateRequest extends FormRequest
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
            'name' => 'required|string|max:255',
            'visible_columns' => 'required|array',
            'visible_columns.*' => 'boolean',
            'column_widths' => 'required|array',
            'column_widths.*' => 'string|nullable',
            'column_order' => 'required|array',
            'column_order.*' => 'string',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'visible_columns.required' => 'Visible columns configuration is required.',
            'column_widths.required' => 'Column widths configuration is required.',
            'column_order.required' => 'Column order configuration is required.',
        ];
    }
}
