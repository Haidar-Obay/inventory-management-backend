<?php

namespace Database\Seeders;

use App\Models\Association;
use App\Models\AssociationContact;
use Illuminate\Database\Seeder;

class AssociationContactSeeder extends Seeder
{
    public function run(): void
    {
        $association = Association::first();
        if (!$association) {
            return;
        }

        AssociationContact::updateOrCreate([
            'association_id' => $association->id,
            'contact_email' => 'sarah.j@healthplus.org',
        ], [
            'contact_name' => 'Sarah Johnson',
            'contact_phone' => '+1-202-555-0123',
        ]);

        AssociationContact::updateOrCreate([
            'association_id' => $association->id,
            'contact_email' => 'ops@healthplus.org',
        ], [
            'contact_name' => 'Operations Desk',
            'contact_phone' => '+1-202-555-0110',
        ]);
    }
}


