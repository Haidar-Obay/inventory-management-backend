<?php

namespace App\Http\Requests\TransportationChannel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTransportationChannelRequest extends FormRequest
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
                Rule::unique('transportation_channels')->ignore($this->transportation_channel),
            ],
            'name' => 'required|string|max:255',
            'sub_transportation_of' => 'nullable|exists:transportation_channels,id',
        ];
    }

    public function messages()
    {
        return [
            'code.required' => 'The transportation channel code is required.',
            'code.unique' => 'This transportation channel code is already in use.',
            'name.required' => 'The transportation channel name is required.',
            'sub_transportation_of.exists' => 'The selected parent transportation channel does not exist.',
        ];
    }
}
