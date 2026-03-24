<?php

declare(strict_types=1);

namespace App\Actions\Supplier;

use App\Http\Resources\Supplier\SupplierAttachmentResource;
use App\Models\Supplier;

class GetSupplierAttachmentsAction
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function execute(Supplier $supplier): array
    {
        $attachments = $supplier->attachments()->orderBy('created_at', 'desc')->get();

        return SupplierAttachmentResource::collection($attachments)->resolve();
    }
}
