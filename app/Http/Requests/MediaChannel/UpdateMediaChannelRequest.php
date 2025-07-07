<?php

namespace App\Http\Requests\MediaChannel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMediaChannelRequest extends FormRequest
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
                Rule::unique('media_channels')->ignore($this->media_channel),
            ],
            'name' => 'required|string|max:255',
            'sub_media_of' => 'nullable|exists:media_channels,id',
        ];
    }

    public function messages()
    {
        return [
            'code.required' => 'The media channel code is required.',
            'code.unique' => 'This media channel code is already in use.',
            'name.required' => 'The media channel name is required.',
            'sub_media_of.exists' => 'The selected parent media channel does not exist.',
        ];
    }
}
