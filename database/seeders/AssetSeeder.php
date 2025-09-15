<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\Section;
use Illuminate\Database\Seeder;

class AssetSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $sections = Section::all();
        $assetTypes = ['machine', 'bed', 'equipment', 'furniture', 'other'];
        $assetStatuses = ['active', 'maintenance', 'inactive', 'retired'];
        
        foreach ($sections as $section) {
            // Random number of assets between 1 and 3 for each section
            $assetCount = rand(1, 3);
            
            for ($i = 1; $i <= $assetCount; $i++) {
                Asset::create([
                    'section_id' => $section->id,
                    'name' => "Asset {$i} - " . ucfirst($assetTypes[array_rand($assetTypes)]),
                    'type' => $assetTypes[array_rand($assetTypes)],
                    'status' => $assetStatuses[array_rand($assetStatuses)],
                ]);
            }
        }
    }
}
