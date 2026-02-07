<?php

namespace App\Http\Requests\Item;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Accepts either a single "attachment" (legacy) or multiple "attachments[]".
 * Used by the same POST items/{item}/attachments endpoint for both flows.
 */
class UploadItemAttachmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $fileRule = 'file|max:10240';
        return [
            'attachment' => "required_without:attachments.0|nullable|{$fileRule}",
            'attachments' => 'required_without:attachment|nullable|array',
            'attachments.*' => $fileRule,
            'description' => 'nullable|string|max:255',
            'category' => 'nullable|string|in:photo,document,other',
            'data' => 'nullable|string',
        ];
    }
}
