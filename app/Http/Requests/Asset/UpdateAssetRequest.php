<?php

namespace App\Http\Requests\Asset;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $asset = $this->route('asset');
        $assetId = $asset?->id ?? $asset;
        $sectionId = $this->input('section_id') ?? $asset?->section_id;
        
        return [
            'section_id' => 'sometimes|exists:sections,id',
            'name' => [
                'sometimes',
                'string',
                'max:255',
                $sectionId ? Rule::unique('assets', 'name')
                    ->where('section_id', $sectionId)
                    ->ignore($assetId) : 'sometimes',
            ],
            'type' => 'sometimes|in:machine,bed,equipment,furniture,other',
            'status' => 'sometimes|in:active,maintenance,inactive,retired',
        ];
    }

    public function messages(): array
    {
        return [
            'section_id.exists' => 'The selected section does not exist.',
            'name.string' => 'The asset name must be a string.',
            'name.max' => 'The asset name cannot exceed 255 characters.',
            'type.in' => 'The asset type must be one of: machine, bed, equipment, furniture, other.',
            'status.in' => 'The asset status must be one of: active, maintenance, inactive, retired.',
        ];
    }
}
