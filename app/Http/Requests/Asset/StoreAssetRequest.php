<?php

namespace App\Http\Requests\Asset;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $sectionId = $this->input('section_id');
        
        return [
            'section_id' => 'required|exists:sections,id',
            'name' => [
                'required',
                'string',
                'max:255',
                $sectionId ? Rule::unique('assets', 'name')->where('section_id', $sectionId) : 'required',
            ],
            'type' => 'required|in:machine,bed,equipment,furniture,other',
            'status' => 'required|in:active,maintenance,inactive,retired',
        ];
    }

    public function messages(): array
    {
        return [
            'section_id.required' => 'The section ID is required.',
            'section_id.exists' => 'The selected section does not exist.',
            'name.required' => 'The asset name is required.',
            'name.string' => 'The asset name must be a string.',
            'name.max' => 'The asset name cannot exceed 255 characters.',
            'name.unique' => 'An asset with this name already exists in the selected section.',
            'type.required' => 'The asset type is required.',
            'type.in' => 'The asset type must be one of: machine, bed, equipment, furniture, other.',
            'status.required' => 'The asset status is required.',
            'status.in' => 'The asset status must be one of: active, maintenance, inactive, retired.',
        ];
    }
}
