<?php

namespace App\Http\Requests\CustomerMasterList;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerMasterListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => 'required|date',
            'name' => 'required|string|max:255|unique:customer_master_lists,name',
            'valid_from' => 'required|date',
            'valid_till' => 'required|date|after_or_equal:valid_from',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|exists:items,id',
            'items.*.price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'date.required' => 'Date is required.',
            'date.date' => 'Date must be a valid date.',
            'name.required' => 'Name is required.',
            'name.string' => 'Name must be a string.',
            'name.max' => 'Name may not be greater than 255 characters.',
            'name.unique' => 'A customer master list with this name already exists. Please choose a different name.',
            'valid_from.required' => 'Valid from date is required.',
            'valid_from.date' => 'Valid from must be a valid date.',
            'valid_till.required' => 'Valid till date is required.',
            'valid_till.date' => 'Valid till must be a valid date.',
            'valid_till.after_or_equal' => 'Valid till must be a date after or equal to valid from.',
            'items.required' => 'Items are required.',
            'items.array' => 'Items must be an array.',
            'items.min' => 'At least one item is required.',
            'items.*.item_id.required' => 'Item ID is required for each item.',
            'items.*.item_id.exists' => 'Selected item does not exist.',
            'items.*.price.required' => 'Price is required for each item.',
            'items.*.price.numeric' => 'Price must be a number.',
            'items.*.price.min' => 'Price must be at least 0.',
            'items.*.discount.nullable' => 'Discount can be null.',
            'items.*.discount.numeric' => 'Discount must be a number.',
            'items.*.discount.min' => 'Discount must be at least 0.',
            'items.*.discount.max' => 'Discount may not be greater than 100.',
        ];
    }
}
