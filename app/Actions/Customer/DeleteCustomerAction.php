<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;

class DeleteCustomerAction
{
    public function execute(Customer $customer): void
    {
        $addressIds = $customer->addresses()->pluck('addresses.id')->all();

        // Addresses will be automatically deleted via cascade foreign key
        $customer->delete();

        // Remove addresses that became orphaned (not linked to any customer or supplier)
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

        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_customer_names");
    }
}

