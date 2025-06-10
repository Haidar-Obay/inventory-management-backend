<?php

namespace App\Http\Requests\TransactionSeries;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransactionSeriesRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'code' => 'required|string|max:50|unique:transaction_series,code',
            'name' => 'required|string|max:255',
            'template' => 'required|string|max:255',
            'company_code_id' => 'required|exists:company_codes,id',
            'trade_id' => 'required|exists:trades,id',
        ];
    }

    public function messages()
    {
        return [
            'code.required' => 'The transaction series code is required.',
            'code.unique' => 'This transaction series code is already in use.',
            'name.required' => 'The transaction series name is required.',
            'template.required' => 'The template is required.',
            'company_code_id.required' => 'The company code is required.',
            'company_code_id.exists' => 'The selected company code does not exist.',
            'trade_id.required' => 'The trade is required.',
            'trade_id.exists' => 'The selected trade does not exist.',
        ];
    }
}
