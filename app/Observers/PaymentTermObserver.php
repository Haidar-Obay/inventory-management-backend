<?php

namespace App\Observers;

use App\Models\PaymentTerm;

class PaymentTermObserver
{
    /**
     * Ensure at most one payment term has primary = true.
     * When this model is saved with primary = true, set primary = false on all others.
     */
    public function saving(PaymentTerm $paymentTerm): void
    {
        if (! $paymentTerm->primary) {
            return;
        }

        $query = PaymentTerm::query()->where('primary', true);

        if ($paymentTerm->exists) {
            $query->where('id', '!=', $paymentTerm->id);
        }

        $query->update(['primary' => false]);
    }
}
