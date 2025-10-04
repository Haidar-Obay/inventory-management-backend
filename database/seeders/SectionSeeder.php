<?php

namespace Database\Seeders;

use App\Models\Room;
use App\Models\Section;
use Illuminate\Database\Seeder;

class SectionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $rooms = Room::all();

        foreach ($rooms as $room) {
            // Create 2 sections for each room
            for ($i = 1; $i <= 2; $i++) {
                Section::create([
                    'room_id' => $room->id,
                    'name' => "Section {$i}",
                    'order_index' => $i,
                ]);
            }
        }
    }
}
