<?php

namespace App\Http\Requests\DistributionChannel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDistributionChannelRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('distribution_channels')->ignore($this->distribution_channel),
            ],
            'name' => 'required|string|max:255',
            'sub_distribution_of' => 'nullable|exists:distribution_channels,id',
        ];
    }

    public function messages()
    {
        return [
            'code.required' => 'The distribution channel code is required.',
            'code.unique' => 'This distribution channel code is already in use.',
            'name.required' => 'The distribution channel name is required.',
            'sub_distribution_of.exists' => 'The selected parent distribution channel does not exist.',
        ];
    }
}
