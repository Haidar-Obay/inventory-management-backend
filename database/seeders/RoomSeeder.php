<?php

namespace Database\Seeders;

use App\Models\Room;
use Illuminate\Database\Seeder;

class RoomSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $rooms = [
            [
                'name' => 'Conference Room A',
                'location' => 'First Floor, East Wing',
            ],
            [
                'name' => 'Conference Room B',
                'location' => 'First Floor, West Wing',
            ],
        ];

        foreach ($rooms as $room) {
            Room::create($room);
        }
    }
}
