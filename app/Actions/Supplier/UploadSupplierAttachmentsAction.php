<?php

declare(strict_types=1);

namespace App\Actions\Supplier;

use App\Models\Supplier;
use App\Models\SupplierAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class UploadSupplierAttachmentsAction
{
    /**
     * @return array<int, SupplierAttachment>
     */
    public function execute(Request $request, Supplier $supplier): array
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
                "tenants/{$tenantId}/suppliers/{$supplier->id}/attachments",
                $file
            );
            $meta = $metadata[$index] ?? [];
            $created[] = SupplierAttachment::create([
                'supplier_id' => $supplier->id,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => url(Storage::url($path)),
                'file_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'description' => $meta['description'] ?? '',
                'category' => $meta['category'] ?? 'document',
                'is_public' => $meta['is_public'] ?? true,
            ]);
        }

        return $created;
    }
}
