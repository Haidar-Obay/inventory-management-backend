<?php

namespace App\Http\Requests\Item;

use App\Enums\ItemType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|unique:items,code',
            'name' => 'required|string',
            'type' => ['required', Rule::enum(ItemType::class)],
            'price' => 'required|numeric|min:0',
            'base_uom_id' => 'nullable|integer|exists:unit_of_measurements,id',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'max_discount' => 'nullable|numeric|min:0',
            'purchase_parameters' => 'nullable|array',
            'purchase_description' => 'nullable|string',
            'purchase_uom_id' => 'nullable|integer|exists:unit_of_measurements,id',
            'sales_parameters' => 'nullable|array',
            'sales_description' => 'nullable|string',
            'pos_description' => 'nullable|string',
            'sales_uom_id' => 'nullable|integer|exists:unit_of_measurements,id',
            'unit' => 'nullable|string|max:255',
            'trade_id' => 'nullable|integer|exists:trades,id',
            'company_code_id' => 'nullable|integer|exists:company_codes,id',
            'product_line_id' => 'nullable|integer|exists:product_lines,id',
            'category_id' => 'nullable|integer|exists:categories,id',
            'brand_id' => 'nullable|integer|exists:brands,id',
            'description' => 'nullable|string',
            'parent_id' => 'nullable|integer|exists:items,id',
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'The item code is required.',
            'code.unique' => 'The code has already been taken.',
            'name.required' => 'The item name is required.',
            'price.required' => 'The item price is required.',
            'price.numeric' => 'The price must be a number.',
            'price.min' => 'The price cannot be negative.',
        ];
    }
}
