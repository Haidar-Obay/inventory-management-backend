<?php

declare(strict_types=1);

namespace App\Actions\Supplier;

use App\Models\Supplier;
use App\Models\SupplierAttachment;
use Illuminate\Support\Facades\Storage;

class DeleteSupplierAttachmentAction
{
    public function execute(Supplier $supplier, SupplierAttachment $attachment): bool
    {
        if ($attachment->supplier_id !== (int) $supplier->id) {
            return false;
        }

        $filePath = str_replace(url('storage/'), '', $attachment->file_path);
        $filePath = str_replace(url('/storage/'), '', $filePath);
        if (Storage::disk('public')->exists($filePath)) {
            Storage::disk('public')->delete($filePath);
        }
        $attachment->delete();

        return true;
    }
}

