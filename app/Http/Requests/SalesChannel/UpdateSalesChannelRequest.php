<?php

namespace App\Http\Requests\SalesChannel;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSalesChannelRequest extends FormRequest
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
                Rule::unique('sales_channels')->ignore($this->sales_channel),
            ],
            'name' => 'required|string|max:255',
            'sub_sales_of' => 'nullable|exists:sales_channels,id',
        ];
    }

    public function messages()
    {
        return [
            'code.required' => 'The sales channel code is required.',
            'code.unique' => 'This sales channel code is already in use.',
            'name.required' => 'The sales channel name is required.',
            'sub_sales_of.exists' => 'The selected parent sales channel does not exist.',
        ];
    }
}
