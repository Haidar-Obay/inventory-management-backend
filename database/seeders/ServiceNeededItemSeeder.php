<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\Service;
use App\Models\ServiceNeededItem;
use Illuminate\Database\Seeder;

class ServiceNeededItemSeeder extends Seeder
{
    public function run(): void
    {
        $service = Service::where('name', 'MRI Brain Scan')->first();
        $item = Item::first();
        if ($service && $item) {
            ServiceNeededItem::updateOrCreate([
                'service_id' => $service->id,
                'item_id' => $item->id,
            ], [
                'quantity' => 1.000,
            ]);
        }
    }
}
