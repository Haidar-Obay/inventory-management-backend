<?php

declare(strict_types=1);

namespace App\Actions\Supplier;

use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BulkDeleteSuppliersAction
{
    public function execute(Request $request): array
    {
        $ids = $request->input('ids');
        $skipped = [];
        $deleted = 0;

        foreach ($ids as $id) {
            try {
                $supplier = Supplier::find($id);

                if (! $supplier) {
                    $skipped[] = [
                        'id' => $id,
                        'reason' => 'Supplier not found.',
                    ];

                    continue;
                }

                // Delete related data
                $supplier->contacts()->delete();
                $supplier->attachments()->delete();

                // Addresses will be automatically deleted via cascade foreign key
                $supplier->delete();
                $deleted++;

            } catch (\Exception $e) {
                Log::error('Error deleting supplier '.$id.': '.$e->getMessage());
                $skipped[] = [
                    'id' => $id,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return [
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ];
    }
}

