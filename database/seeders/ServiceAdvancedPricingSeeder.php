<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\ServiceAdvancedPricing;
use App\Models\Specialist;
use Illuminate\Database\Seeder;

class ServiceAdvancedPricingSeeder extends Seeder
{
    public function run(): void
    {
        $service = Service::where('name', 'MRI Brain Scan')->first();
        $specialist = Specialist::first();
        if ($service && $specialist) {
            ServiceAdvancedPricing::updateOrCreate([
                'service_id' => $service->id,
                'specialist_id' => $specialist->id,
            ], [
                'price_on_site' => 275,
                'price_on_call' => 290,
            ]);
        }
    }
}
