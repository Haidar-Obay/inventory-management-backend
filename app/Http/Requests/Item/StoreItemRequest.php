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

    /**
     * Override the validation behavior to handle FormData with 'data' field
     */
    protected function prepareForValidation()
    {
        // If this is FormData with a 'data' field, decode it and merge into request
        if ($this->has('data')) {
            $data = json_decode($this->input('data'), true);
            if (is_array($data)) {
                // If files are being uploaded via 'attachments' field, exclude attachments from merged data
                // to avoid validation conflict (Laravel will see attachments as file uploads, not array)
                if ($this->hasFile('attachments')) {
                    unset($data['attachments']);
                }
                $this->merge($data);
            }
        }
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|unique:items,code',
            'name' => 'required|string',
            'type' => ['required', Rule::enum(ItemType::class)],
            'base_uom_id' => 'nullable|integer|exists:unit_of_measurements,id',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'max_discount' => 'nullable|numeric|min:0',

            // Flags
            'active' => 'sometimes|boolean',
            'allow_discount' => 'sometimes|boolean',
            'allow_credit' => 'sometimes|boolean',
            'allow_return' => 'sometimes|boolean',
            'available_for_sale' => 'sometimes|boolean',
            'raw_material' => 'sometimes|boolean',
            'produced_item' => 'sometimes|boolean',
            // purchase/sales parameters removed
            'purchase_description' => 'nullable|string',
            'purchase_uom_id' => 'nullable|integer|exists:unit_of_measurements,id',
            'sales_description' => 'nullable|string',
            'pos_description' => 'nullable|string',
            'sales_uom_id' => 'nullable|integer|exists:unit_of_measurements,id',
            'trade_id' => 'nullable|integer|exists:trades,id',
            'company_code_id' => 'nullable|integer|exists:company_codes,id',
            'product_line_id' => 'nullable|integer|exists:product_lines,id',
            'category_id' => 'nullable|integer|exists:categories,id',
            'brand_id' => 'nullable|integer|exists:brands,id',
            'parent_id' => 'nullable|integer|exists:items,id',
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'The item code is required.',
            'code.unique' => 'The code has already been taken.',
            'name.required' => 'The item name is required.',
        ];
    }
}
