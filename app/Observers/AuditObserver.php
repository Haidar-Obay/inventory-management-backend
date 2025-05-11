<?php

namespace App\Observers;
use App\Events\AuditCreated;
use OwenIt\Auditing\Models\Audit;

class AuditObserver
{
    /**
     * Handle the Audit "created" event.
     */
    public function created(Audit $audit)
    {
        event(new AuditCreated($audit));
    }
    /**
     * Handle the Audit "updated" event.
     */
    public function updated(Audit $audit): void
    {
        //
    }

    /**
     * Handle the Audit "deleted" event.
     */
    public function deleted(Audit $audit): void
    {
        //
    }

    /**
     * Handle the Audit "restored" event.
     */
    public function restored(Audit $audit): void
    {
        //
    }

    /**
     * Handle the Audit "force deleted" event.
     */
    public function forceDeleted(Audit $audit): void
    {
        //
    }
}
