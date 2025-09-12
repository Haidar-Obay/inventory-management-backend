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
            [
                'name' => 'Meeting Room 1',
                'location' => 'Second Floor, North Wing',
            ],
            [
                'name' => 'Meeting Room 2',
                'location' => 'Second Floor, South Wing',
            ],
            [
                'name' => 'Training Room',
                'location' => 'Third Floor, Central Area',
            ],
            [
                'name' => 'Board Room',
                'location' => 'Ground Floor, Executive Wing',
            ],
            [
                'name' => 'Break Room',
                'location' => 'First Floor, Central Area',
            ],
            [
                'name' => 'Storage Room',
                'location' => 'Basement Level',
            ],
        ];

        foreach ($rooms as $room) {
            Room::create($room);
        }
    }
}
