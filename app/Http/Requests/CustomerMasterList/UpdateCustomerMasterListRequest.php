<?php

namespace App\Http\Requests\CustomerMasterList;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerMasterListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => 'sometimes|required|date',
            'name' => 'sometimes|required|string|max:255',
            'valid_from' => 'sometimes|required|date',
            'valid_till' => 'sometimes|required|date|after_or_equal:valid_from',
            'items' => 'sometimes|required|array|min:1',
            'items.*.item_id' => 'required_with:items|exists:items,id',
            'items.*.price' => 'required_with:items|numeric|min:0',
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
            'valid_from.required' => 'Valid from date is required.',
            'valid_from.date' => 'Valid from must be a valid date.',
            'valid_till.required' => 'Valid till date is required.',
            'valid_till.date' => 'Valid till must be a valid date.',
            'valid_till.after_or_equal' => 'Valid till must be a date after or equal to valid from.',
            'items.required' => 'Items are required.',
            'items.array' => 'Items must be an array.',
            'items.min' => 'At least one item is required.',
            'items.*.item_id.required_with' => 'Item ID is required when items are provided.',
            'items.*.item_id.exists' => 'Selected item does not exist.',
            'items.*.price.required_with' => 'Price is required for each item when items are provided.',
            'items.*.price.numeric' => 'Price must be a number.',
            'items.*.price.min' => 'Price must be at least 0.',
            'items.*.discount.nullable' => 'Discount can be null.',
            'items.*.discount.numeric' => 'Discount must be a number.',
            'items.*.discount.min' => 'Discount must be at least 0.',
            'items.*.discount.max' => 'Discount may not be greater than 100.',
        ];
    }
}
