<?php

declare(strict_types=1);

namespace App\Actions\Supplier;

use App\Models\Supplier;

class DeleteSupplierAction
{
    public function execute(Supplier $supplier): void
    {
        // Delete related records first
        $supplier->contacts()->delete();
        $supplier->attachments()->delete();
        $supplier->openingBalances()->delete();

        // Addresses will be automatically deleted via cascade foreign key
        $supplier->delete();
    }
}
