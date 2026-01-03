<?php

namespace App\Http\Requests\Invoice;

use App\Enums\InvoiceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invoice_type' => ['sometimes', Rule::enum(InvoiceType::class)],
            'date' => 'sometimes|date',
            'due_date' => 'nullable|date|after_or_equal:date',

            // Relationships - conditional based on invoice type
            'customer_id' => [
                'nullable',
                'integer',
                'exists:customers,id',
            ],
            'supplier_id' => [
                'nullable',
                'integer',
                'exists:suppliers,id',
            ],
            'currency_id' => 'sometimes|integer|exists:currencies,id',
            'salesman_id' => [
                'nullable',
                'integer',
                'exists:salesmen,id',
            ],
            'warehouse_id' => 'sometimes|integer|exists:warehouses,id',
            'payment_term_id' => 'nullable|integer|exists:payment_terms,id',

            // Reference
            'ref_2' => 'nullable|string|max:255',

            // Document-level discount
            'discount_2_type' => 'nullable|in:percent,amount',
            'discount_2_value' => 'nullable|numeric|min:0',

            // Financial totals (optional, will be calculated)
            'subtotal' => 'nullable|numeric|min:0',
            'taxes' => 'nullable|numeric|min:0',
            'net_total' => 'nullable|numeric',
            'adjustment' => 'nullable|numeric',
            'net_to_pay' => 'nullable|numeric',

            // Notes
            'notes' => 'nullable|string',

            // Contact info (JSON arrays)
            'billing_to_phones' => 'nullable|array',
            'billing_to_phones.*' => 'string',
            'billing_to_addresses' => 'nullable|array',
            'billing_to_addresses.*' => 'string',
            'shipping_to_phones' => 'nullable|array',
            'shipping_to_phones.*' => 'string',
            'shipping_to_addresses' => 'nullable|array',
            'shipping_to_addresses.*' => 'string',

            // Invoice items
            'items' => 'sometimes|array|min:1',
            'items.*.item_id' => 'required_with:items|integer|exists:items,id',
            'items.*.barcode' => 'nullable|string',
            'items.*.description' => 'required_with:items|string',
            'items.*.uom_id' => 'required_with:items|integer|exists:unit_of_measurements,id',
            'items.*.warehouse_id' => 'required_with:items|integer|exists:warehouses,id',
            'items.*.quantity' => 'required_with:items|numeric|min:0.0001',
            'items.*.price' => 'required_with:items|numeric|min:0',
            'items.*.unit_price' => 'nullable|numeric|min:0',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'items.*.tax_percent' => 'nullable|numeric|min:0|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'invoice_type.required' => 'The invoice type is required.',
            'date.date' => 'The invoice date must be a valid date.',
            'items.min' => 'At least one item is required.',
            'items.*.item_id.required_with' => 'Item ID is required for each invoice item.',
            'items.*.quantity.required_with' => 'Quantity is required for each invoice item.',
            'items.*.price.required_with' => 'Price is required for each invoice item.',
        ];
    }
}
