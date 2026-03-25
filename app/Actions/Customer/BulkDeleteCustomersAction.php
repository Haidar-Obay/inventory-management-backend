<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Models\Customer;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BulkDeleteCustomersAction
{
    public function execute(Request $request): array
    {
        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            try {
                // Collect address IDs for this customer
                $customer = Customer::with('addresses:id')->find($id);
                $addressIds = $customer ? $customer->addresses()->pluck('addresses.id')->all() : [];

                // Skip if customer has projects and include details
                if ($customer) {
                    $projectsCount = Project::where('customer_id', $customer->id)->count();
                    if ($projectsCount > 0) {
                        $details = [
                            'projects' => [
                                'count' => $projectsCount,
                                'sample_ids' => Project::where('customer_id', $customer->id)
                                    ->select('projects.id')
                                    ->limit(1)
                                    ->pluck('id'),
                            ],
                        ];

                        $skipped[] = [
                            'id' => $id,
                            'reason' => 'Cannot delete customer. It is referenced by existing projects.',
                            'details' => $details,
                        ];

                        continue;
                    }
                }

                $deleted += Customer::where('id', $id)->delete();

                // Cleanup orphaned addresses for this customer
                if (! empty($addressIds)) {
                    DB::table('addresses')
                        ->whereIn('id', $addressIds)
                        ->whereNotExists(function ($q) {
                            $q->select(DB::raw(1))
                                ->from('customer_addresses')
                                ->whereColumn('customer_addresses.address_id', 'addresses.id');
                        })
                        ->whereNotExists(function ($q) {
                            $q->select(DB::raw(1))
                                ->from('supplier_addresses')
                                ->whereColumn('supplier_addresses.address_id', 'addresses.id');
                        })
                        ->delete();
                }
            } catch (\Illuminate\Database\QueryException $e) {
                // Check if it's a foreign key constraint error and include details
                if ($e->getCode() == '23503') {
                    $details = [];

                    try {
                        $customer = Customer::find($id);
                        $projectsCount = $customer ? Project::where('customer_id', $customer->id)->count() : 0;
                        if ($projectsCount > 0) {
                            $details['projects'] = [
                                'count' => $projectsCount,
                                'sample_ids' => Project::where('customer_id', $customer->id)
                                    ->select('projects.id')
                                    ->limit(1)
                                    ->pluck('id'),
                            ];
                        }
                    } catch (\Throwable $ignored) {
                    }

                    $skipped[] = [
                        'id' => $id,
                        'reason' => 'Cannot delete customer. It is referenced by existing projects.',
                        'details' => $details,
                    ];
                } else {
                    $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
                }
            }
        }

        // Clear customer names cache after bulk delete
        $tenantId = tenant('id');
        $cacheKey = "tenant_{$tenantId}_customer_names";
        app('cache')->store('database')->forget($cacheKey);

        return [
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ];
    }
}
