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
            'items.*.line' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'date.required' => 'The date is required.',
            'date.date' => 'The date must be a valid date.',
            'name.required' => 'The name is required.',
            'name.string' => 'The name must be a string.',
            'name.max' => 'The name may not be greater than 255 characters.',
            'valid_from.required' => 'The valid from date is required.',
            'valid_from.date' => 'The valid from date must be a valid date.',
            'valid_till.required' => 'The valid till date is required.',
            'valid_till.date' => 'The valid till date must be a valid date.',
            'valid_till.after_or_equal' => 'The valid till date must be after or equal to the valid from date.',
            'items.required' => 'Items are required.',
            'items.array' => 'Items must be an array.',
            'items.min' => 'At least one item is required.',
            'items.*.item_id.required_with' => 'Each item must include item_id.',
            'items.*.item_id.exists' => 'One of the selected items does not exist.',
            'items.*.price.required_with' => 'Each item must include price.',
            'items.*.price.numeric' => 'Item price must be a number.',
            'items.*.price.min' => 'Item price cannot be negative.',
            'items.*.discount.numeric' => 'Item discount must be a number.',
            'items.*.discount.min' => 'Item discount cannot be negative.',
            'items.*.discount.max' => 'Item discount cannot be greater than 100%.',
            'items.*.line.string' => 'Item line must be a string.',
            'items.*.line.max' => 'Item line may not be greater than 255 characters.',
        ];
    }
}
