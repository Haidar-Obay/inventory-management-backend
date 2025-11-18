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
    protected $description = 'Update appointment statuses based on current time (active, in_progress, completed). Recommended: Run every 5-15 minutes via cron.';

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
                // Get appointments that might need status updates
                // Check all appointments to ensure accuracy
                $appointments = Appointment::where(function ($query) use ($now) {
                    // Get appointments that are either:
                    // 1. Active but should be in_progress or completed
                    // 2. In progress but should be completed
                    $query->where(function ($q) use ($now) {
                        $q->where('status', 'active')
                            ->where('start_at', '<=', $now);
                    })->orWhere(function ($q) use ($now) {
                        $q->where('status', 'in_progress')
                            ->where('end_at', '<=', $now);
                    });
                })->get();

                $updated = 0;
                foreach ($appointments as $appointment) {
                    $newStatus = $appointment->calculateStatus();
                    if ($appointment->status !== $newStatus) {
                        $appointment->status = $newStatus;
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

