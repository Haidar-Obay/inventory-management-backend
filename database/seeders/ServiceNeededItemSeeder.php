<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\Service;
use App\Models\ServiceNeededItem;
use Illuminate\Database\Seeder;

class ServiceNeededItemSeeder extends Seeder
{
    public function run(): void
    {
        $service = Service::where('name', 'MRI Brain Scan')->first();
        $asset = Asset::first();
        if ($service && $asset) {
            ServiceNeededItem::updateOrCreate([
                'service_id' => $service->id,
                'asset_id' => $asset->id,
            ], [
                'description' => 'Head coil for MRI',
                'unit' => 'pcs',
                'qty' => 1.000,
                'notes_multiline' => "Ensure coil is calibrated.\nReplace padding if worn.",
            ]);
        }
    }
}
