<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\Tenant;
use Illuminate\Console\Command;

class UpdateAppointmentStatuses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'appointments:update-statuses';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update appointment statuses to active if before start_at. in_progress and completed are managed by visits. Recommended: Run every 5-15 minutes via cron.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Updating appointment statuses...');

        $now = now();
        $totalUpdated = 0;

        // Get all tenants (if multi-tenant)
        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            $tenant->run(function () use ($now, $tenant, &$totalUpdated) {
                // Get appointments that might need status updates to 'active'
                // Exclude appointments managed by visits (in_progress, completed, cancelled)
                // Only update appointments that should be 'active' (before start_at)
                $appointments = Appointment::whereNotIn('status', ['in_progress', 'completed', 'cancelled'])
                    ->where(function ($query) use ($now) {
                        // Get appointments that are:
                        // 1. null status or 'active' status and before start_at (should be active)
                        $query->where(function ($q) use ($now) {
                            $q->where(function ($q2) {
                                $q2->whereNull('status')->orWhere('status', 'active');
                            })->where('start_at', '>', $now);
                        });
                    })->get();

                $updated = 0;
                foreach ($appointments as $appointment) {
                    $calculatedStatus = $appointment->calculateStatus();
                    // Only set to 'active' if calculated status is 'active'
                    if ($calculatedStatus === 'active' && $appointment->status !== 'active') {
                        $appointment->status = 'active';
                        $appointment->saveQuietly();
                        $updated++;
                    }
                }

                if ($updated > 0) {
                    // Clear cache for this tenant
                    $tenantId = tenant('id');
                    app('cache')->store('database')->forget("tenant_{$tenantId}_appointments");
                    $this->info("Tenant {$tenant->id}: Updated {$updated} appointment(s)");
                }

                $totalUpdated += $updated;
            });
        }

        if ($totalUpdated > 0) {
            $this->info("Total appointments updated: {$totalUpdated}");
        } else {
            $this->info('No appointments needed status updates.');
        }

        return Command::SUCCESS;
    }
}
