<?php

namespace App\Observers;

use App\Models\Warehouse;

class WarehouseObserver
{
    /**
     * Ensure at most one warehouse has each default flag (purchases, sales, storage).
     * When this warehouse is saved with one of the flags true, set that flag false on all others.
     */
    public function saving(Warehouse $warehouse): void
    {
        foreach (['default_for_purchases', 'default_for_sales', 'default_for_storage'] as $attribute) {
            if (! $warehouse->{$attribute}) {
                continue;
            }

            $query = Warehouse::query()->where($attribute, true);

            if ($warehouse->exists) {
                $query->where('id', '!=', $warehouse->id);
            }

            $query->update([$attribute => false]);
        }
    }
}
