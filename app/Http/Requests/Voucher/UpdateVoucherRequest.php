<?php

namespace App\Http\Requests\Voucher;

use App\Enums\VoucherType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', Rule::enum(VoucherType::class)],
            'date' => 'sometimes|date',
            'effective_date' => 'nullable|date',
            'ref_2' => 'nullable|string|max:255',
            'currency_id' => 'sometimes|integer|exists:currencies,id',
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
            'lines' => 'sometimes|array|min:1',
            'lines.*.cash_account_id' => 'required_with:lines|integer|exists:cash_accounts,id',
            'lines.*.currency_id' => 'required_with:lines|integer|exists:currencies,id',
            'lines.*.exchange_rate' => 'nullable|numeric|min:0',
            'lines.*.amount' => 'required_with:lines|numeric|min:0',
            'lines.*.remark' => 'nullable|string|max:255',
        ];
    }
}
