<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Specialist;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        $imaging = Department::where('code', 'IMG')->first();
        $mriUnit = Department::where('code', 'MRI')->first();

        // Get a service category
        $imagingCategory = ServiceCategory::where('name', 'Imaging Services')->first();

        $service = Service::firstOrCreate([
            'name' => 'MRI Brain Scan',
        ], [
            'service_category_id' => $imagingCategory?->id,
            'department_id' => $imaging?->id,
            'sub_department_id' => $mriUnit?->id,
            'cnss_code' => 'CNSS-99123',
            'result_after_days' => 2,
            'needs_specialist' => true,
            'duration_minutes' => 60,
            'normal_price' => 250,
            'vip_price' => 300,
            'price_in_group' => 220,
            'event_pricing' => false,
            'price_calculated_by_hour' => false,
            'estimated_cost' => 120,
            'image' => null,
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
