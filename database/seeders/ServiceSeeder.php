<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Specialist;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        // Get a service category
        $imagingCategory = ServiceCategory::where('name', 'Imaging Services')->first();

        $service = Service::firstOrCreate([
            'name' => 'MRI Brain Scan',
        ], [
            'service_category_id' => $imagingCategory?->id,
            'result_after_days' => 2,
            'needs_specialist' => true,
            'needs_asset' => true,
            'duration_minutes' => 60,
            'normal_price' => 250,
            'vip_price' => 300,
            'price_in_group' => 220,
            'price_calculated_by_hour' => false,
            'cost_price' => 120,
            'birthday_price' => 200,
            'wedding_price' => 350,
            'service_color' => '#2E86DE',
            'service_sex' => 'both',
            'active' => true,
        ]);

        // Note: Service categories are now simple classification, not pricing
        // Pricing is handled through normal_price, vip_price, etc. on the service itself

        // Attach specialists
        $specialists = Specialist::pluck('id')->take(2)->all();
        if (! empty($specialists)) {
            $service->specialists()->syncWithoutDetaching($specialists);
        }
    }
}
