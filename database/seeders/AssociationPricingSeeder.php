<?php

namespace Database\Seeders;

use App\Models\Association;
use App\Models\AssociationServicePrice;
use App\Models\Service;
use Illuminate\Database\Seeder;

class AssociationPricingSeeder extends Seeder
{
    public function run(): void
    {
        $association = Association::first();
        $service = Service::where('name', 'MRI Brain Scan')->first();
        if ($association && $service) {
            AssociationServicePrice::updateOrCreate([
                'association_id' => $association->id,
                'service_id' => $service->id,
            ], [
                'price' => 240,
                'discount' => 10,
            ]);
        }
    }
}
