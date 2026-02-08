<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadServiceAttachmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $allowedMimes = ['image/jpeg', 'image/png', 'application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/plain'];
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf', 'docx', 'xlsx', 'txt'];

        return [
            'attachments' => 'required|array',
            'attachments.*' => [
                'required',
                'file',
                function ($attribute, $value, $fail) use ($allowedMimes, $allowedExtensions) {
                    if (! ($value instanceof \Illuminate\Http\UploadedFile)) {
                        return;
                    }
                    $mime = $value->getMimeType();
                    $extension = strtolower($value->getClientOriginalExtension());
                    if (! in_array($mime, $allowedMimes) && ! in_array($extension, $allowedExtensions)) {
                        $fail('The '.$attribute.' must be a file of type: jpg, jpeg, png, pdf, docx, xlsx, txt.');
                    }
                    if ($value->getSize() > 10240 * 1024) {
                        $fail('The '.$attribute.' must not be larger than 10MB.');
                    }
                },
            ],
            'data' => 'nullable|string',
        ];
    }
}
