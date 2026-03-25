<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Models\Customer;
use App\Models\CustomerAttachment;
use Illuminate\Support\Facades\Storage;

class DeleteCustomerAttachmentAction
{
    public function execute(Customer $customer, CustomerAttachment $attachment): bool
    {
        if ($attachment->customer_id !== (int) $customer->id) {
            return false;
        }

        $filePath = str_replace(url('storage/'), '', $attachment->file_path);
        $filePath = str_replace(url('/storage/'), '', $filePath);
        if (Storage::disk('public')->exists($filePath)) {
            Storage::disk('public')->delete($filePath);
        }
        $attachment->delete();

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_customer_names");

        return true;
    }
}

