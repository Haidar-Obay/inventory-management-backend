<?php

namespace App\Http\Requests\Item;

use Illuminate\Foundation\Http\FormRequest;

class UploadItemAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'attachment' => 'required|file|max:10240',
            'description' => 'nullable|string|max:255',
            'category' => 'nullable|string|in:photo,document,other',
        ];
    }
}
