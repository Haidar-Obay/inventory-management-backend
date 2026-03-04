<?php

namespace App\Http\Requests\Voucher;

use App\Enums\VoucherType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(VoucherType::class)],
            'date' => 'required|date',
            'effective_date' => 'nullable|date',
            'ref_2' => 'nullable|string|max:255',
            'currency_id' => 'required|integer|exists:currencies,id',
            'exchange_rate' => 'nullable|numeric|min:0',
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'customer_name' => 'nullable|string|max:255',
            'supplier_name' => 'nullable|string|max:255',
            'opening_balance_currency_id' => 'nullable|integer|exists:currencies,id',
            'opening_balance_amount' => 'nullable|numeric',
            'amount' => 'nullable|numeric|min:0',
            'salesman_id' => 'nullable|integer|exists:salesmen,id',
            'collector_id' => 'nullable|integer|exists:salesmen,id',
            'notes' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.cash_account_id' => 'required|integer|exists:cash_accounts,id',
            'lines.*.currency_id' => 'required|integer|exists:currencies,id',
            'lines.*.exchange_rate' => 'nullable|numeric|min:0',
            'lines.*.amount' => 'required|numeric|min:0',
            'lines.*.remark' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'The voucher type is required.',
            'date.required' => 'The voucher date is required.',
            'currency_id.required' => 'Currency is required.',
            'lines.required' => 'At least one voucher line is required.',
            'lines.*.cash_account_id.required' => 'Cash account is required for each line.',
            'lines.*.currency_id.required' => 'Currency is required for each line.',
            'lines.*.amount.required' => 'Amount is required for each line.',
        ];
    }
}
