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
            // Weight units
            ['name' => 'Kilogram', 'unit_group_id' => $weightGroup->id, 'operation' => 'multiply', 'conversion' => 1.0000],
            ['name' => 'Gram', 'unit_group_id' => $weightGroup->id, 'operation' => 'divide', 'conversion' => 1000.0000],
            ['name' => 'Pound', 'unit_group_id' => $weightGroup->id, 'operation' => 'divide', 'conversion' => 2.2046],
            ['name' => 'Ounce', 'unit_group_id' => $weightGroup->id, 'operation' => 'divide', 'conversion' => 35.2740],

            // Volume units
            ['name' => 'Liter', 'unit_group_id' => $volumeGroup->id, 'operation' => 'multiply', 'conversion' => 1.0000],
            ['name' => 'Milliliter', 'unit_group_id' => $volumeGroup->id, 'operation' => 'divide', 'conversion' => 1000.0000],
            ['name' => 'Gallon', 'unit_group_id' => $volumeGroup->id, 'operation' => 'divide', 'conversion' => 0.2642],

            // Length units
            ['name' => 'Meter', 'unit_group_id' => $lengthGroup->id, 'operation' => 'multiply', 'conversion' => 1.0000],
            ['name' => 'Centimeter', 'unit_group_id' => $lengthGroup->id, 'operation' => 'divide', 'conversion' => 100.0000],
            ['name' => 'Foot', 'unit_group_id' => $lengthGroup->id, 'operation' => 'divide', 'conversion' => 3.2808],

            // Count units
            ['name' => 'Piece', 'unit_group_id' => $countGroup->id, 'operation' => 'multiply', 'conversion' => 1.0000],
            ['name' => 'Box', 'unit_group_id' => $countGroup->id, 'operation' => 'multiply', 'conversion' => 1.0000],
            ['name' => 'Pack', 'unit_group_id' => $countGroup->id, 'operation' => 'multiply', 'conversion' => 1.0000],
            ['name' => 'Carton', 'unit_group_id' => $countGroup->id, 'operation' => 'multiply', 'conversion' => 1.0000],

            // Area units
            ['name' => 'Square Meter', 'unit_group_id' => $areaGroup->id, 'operation' => 'multiply', 'conversion' => 1.0000],
            ['name' => 'Square Foot', 'unit_group_id' => $areaGroup->id, 'operation' => 'divide', 'conversion' => 10.7640],
        ];

        foreach ($unitOfMeasurements as $uom) {
            UnitOfMeasurement::firstOrCreate(
                ['name' => $uom['name'], 'unit_group_id' => $uom['unit_group_id']],
                $uom
            );
        }
    }
}
