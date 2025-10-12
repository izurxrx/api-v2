<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\FacilityType;

class FacilityTypeSeeder extends Seeder
{
    public function run(): void
    {
        $facilityTypes = [
            [
                'name' => 'Accommodation',
                'description' => 'Rooms and lodging facilities',
            ],
            [
                'name' => 'Recreation',
                'description' => 'Swimming pools and recreational areas',
            ],
            [
                'name' => 'Event Space',
                'description' => 'Function halls and event venues',
            ],
            [
                'name' => 'Amenities',
                'description' => 'Additional amenities and services',
            ],
            [
                'name' => 'Dining',
                'description' => 'Restaurants and dining facilities',
            ],
        ];

        foreach ($facilityTypes as $facilityType) {
            FacilityType::create($facilityType);
        }

        $this->command->info('Facility Types created successfully!');
    }
}