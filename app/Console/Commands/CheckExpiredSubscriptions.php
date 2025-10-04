<?php

namespace App\Console\Commands;

use App\Services\SubscriptionService;
use Illuminate\Console\Command;

class CheckExpiredSubscriptions extends Command
{
    protected $signature = 'subscriptions:check-expired';

    protected $description = 'Check and update expired tenant subscriptions';

    protected $subscriptionService;

    public function __construct(SubscriptionService $subscriptionService)
    {
        parent::__construct();
        $this->subscriptionService = $subscriptionService;
    }

    public function handle()
    {
        $this->info('Checking for expired subscriptions...');

        try {
            $result = $this->subscriptionService->checkAndUpdateExpiredSubscriptions();

            if ($result['updated_count'] > 0) {
                $this->info("Updated {$result['updated_count']} expired subscriptions.");
            } else {
                $this->info('No expired subscriptions found.');
            }

            if (! empty($result['errors'])) {
                $this->warn('Some errors occurred:');
                foreach ($result['errors'] as $error) {
                    $this->error($error);
                }
            }

            $this->info('Subscription check completed successfully.');
        } catch (\Exception $e) {
            $this->error('Failed to check expired subscriptions: '.$e->getMessage());

            return 1;
        }

        return 0;
    }
}
