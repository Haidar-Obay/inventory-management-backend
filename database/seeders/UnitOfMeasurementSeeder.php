<?php

namespace Database\Seeders;

use App\Models\UnitGroup;
use App\Models\UnitOfMeasurement;
use Illuminate\Database\Seeder;

class UnitOfMeasurementSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get or create unit groups
        $weightGroup = UnitGroup::firstOrCreate(['name' => 'Weight']);
        $volumeGroup = UnitGroup::firstOrCreate(['name' => 'Volume']);
        $lengthGroup = UnitGroup::firstOrCreate(['name' => 'Length']);
        $countGroup = UnitGroup::firstOrCreate(['name' => 'Count']);
        $areaGroup = UnitGroup::firstOrCreate(['name' => 'Area']);

        $unitOfMeasurements = [
            // Weight units            ['name' => 'Kilogram', 'unit_group_id' => $weightGroup->id],
            ['name' => 'Gram', 'unit_group_id' => $weightGroup->id],
            ['name' => 'Pound', 'unit_group_id' => $weightGroup->id],
            ['name' => 'Ounce', 'unit_group_id' => $weightGroup->id],

            // Volume units
            ['name' => 'Liter', 'unit_group_id' => $volumeGroup->id],
            ['name' => 'Milliliter', 'unit_group_id' => $volumeGroup->id],
            ['name' => 'Gallon', 'unit_group_id' => $volumeGroup->id],

            // Length units
            ['name' => 'Meter', 'unit_group_id' => $lengthGroup->id],
            ['name' => 'Centimeter', 'unit_group_id' => $lengthGroup->id],
            ['name' => 'Foot', 'unit_group_id' => $lengthGroup->id],

            // Count units
            ['name' => 'Piece', 'unit_group_id' => $countGroup->id],
            ['name' => 'Box', 'unit_group_id' => $countGroup->id],
            ['name' => 'Pack', 'unit_group_id' => $countGroup->id],
            ['name' => 'Carton', 'unit_group_id' => $countGroup->id],

            // Area units
            ['name' => 'Square Meter', 'unit_group_id' => $areaGroup->id],
            ['name' => 'Square Foot', 'unit_group_id' => $areaGroup->id],
        ];

        foreach ($unitOfMeasurements as $uom) {
            UnitOfMeasurement::firstOrCreate(
                ['name' => $uom['name'], 'unit_group_id' => $uom['unit_group_id']],
                ['name' => $uom['name'], 'unit_group_id' => $uom['unit_group_id']]
            );
        }
    }
}
