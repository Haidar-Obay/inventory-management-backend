<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Models\Customer;
use App\Models\CustomerAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class UploadCustomerAttachmentsAction
{
    /**
     * @return array<int, CustomerAttachment>
     */
    public function execute(Request $request, Customer $customer): array
    {
        $tenantId = tenant('id');
        $files = $request->file('attachments');
        if (! is_array($files)) {
            $files = $files ? [$files] : [];
        }

        $metadata = [];
        if ($request->has('data')) {
            $decoded = json_decode((string) $request->input('data'), true);
            $metadata = $decoded['attachments'] ?? $decoded ?? [];
        }

        $created = [];
        foreach ($files as $index => $file) {
            if (! $file || ! $file->isValid()) {
                continue;
            }
            $path = Storage::disk('public')->putFile(
                "tenants/{$tenantId}/customers/{$customer->id}/attachments",
                $file
            );
            $meta = $metadata[$index] ?? [];
            $created[] = CustomerAttachment::create([
                'customer_id' => $customer->id,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => url(Storage::url($path)),
                'file_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'description' => $meta['description'] ?? '',
                'category' => $meta['category'] ?? 'document',
                'is_public' => $meta['is_public'] ?? true,
            ]);
        }

        app('cache')->store('database')->forget("tenant_{$tenantId}_customer_names");

        return $created;
    }
}

