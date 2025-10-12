<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Facility;
use App\Models\FacilityType;

class FacilitySeeder extends Seeder
{
    public function run(): void
    {
        $accommodation = FacilityType::where('name', 'Accommodation')->first();
        $recreation = FacilityType::where('name', 'Recreation')->first();
        $eventSpace = FacilityType::where('name', 'Event Space')->first();
        $amenities = FacilityType::where('name', 'Amenities')->first();
        $dining = FacilityType::where('name', 'Dining')->first();

        $facilities = [
            // Accommodation
            [
                'facility_type_id' => $accommodation->id,
                'name' => 'Standard Room',
                'quantity' => 10,
                'expected_capacity' => 2,
                'max_capacity' => 4,
                'description' => 'Comfortable standard rooms with basic amenities',
                'is_maintenance' => 0,
                'is_available_for_booking' => 1,
            ],
            [
                'facility_type_id' => $accommodation->id,
                'name' => 'Deluxe Room',
                'quantity' => 5,
                'expected_capacity' => 2,
                'max_capacity' => 4,
                'description' => 'Deluxe rooms with premium amenities and pool view',
                'is_maintenance' => 0,
                'is_available_for_booking' => 1,
            ],
            [
                'facility_type_id' => $accommodation->id,
                'name' => 'Family Suite',
                'quantity' => 3,
                'expected_capacity' => 4,
                'max_capacity' => 6,
                'description' => 'Spacious family suites with kitchenette',
                'is_maintenance' => 0,
                'is_available_for_booking' => 1,
            ],
            [
                'facility_type_id' => $accommodation->id,
                'name' => 'VIP Villa',
                'quantity' => 2,
                'expected_capacity' => 6,
                'max_capacity' => 8,
                'description' => 'Luxury villa with private pool and garden',
                'is_maintenance' => 0,
                'is_available_for_booking' => 1,
            ],
            
            // Recreation
            [
                'facility_type_id' => $recreation->id,
                'name' => 'Main Swimming Pool',
                'quantity' => 1,
                'expected_capacity' => 30,
                'max_capacity' => 50,
                'description' => 'Large resort swimming pool with slides',
                'is_maintenance' => 0,
                'is_available_for_booking' => 1,
            ],
            [
                'facility_type_id' => $recreation->id,
                'name' => 'Kids Pool',
                'quantity' => 1,
                'expected_capacity' => 10,
                'max_capacity' => 15,
                'description' => 'Shallow pool designed for children',
                'is_maintenance' => 0,
                'is_available_for_booking' => 1,
            ],
            [
                'facility_type_id' => $recreation->id,
                'name' => 'Jacuzzi',
                'quantity' => 1,
                'expected_capacity' => 6,
                'max_capacity' => 8,
                'description' => 'Hot tub and relaxation area',
                'is_maintenance' => 0,
                'is_available_for_booking' => 1,
            ],
            
            // Event Space
            [
                'facility_type_id' => $eventSpace->id,
                'name' => 'Function Hall A',
                'quantity' => 1,
                'expected_capacity' => 50,
                'max_capacity' => 80,
                'description' => 'Large function hall for weddings and events',
                'is_maintenance' => 0,
                'is_available_for_booking' => 1,
            ],
            [
                'facility_type_id' => $eventSpace->id,
                'name' => 'Conference Room',
                'quantity' => 1,
                'expected_capacity' => 20,
                'max_capacity' => 30,
                'description' => 'Meeting and conference room with projector',
                'is_maintenance' => 0,
                'is_available_for_booking' => 1,
            ],
            [
                'facility_type_id' => $eventSpace->id,
                'name' => 'Garden Pavilion',
                'quantity' => 1,
                'expected_capacity' => 30,
                'max_capacity' => 40,
                'description' => 'Outdoor pavilion for garden parties',
                'is_maintenance' => 0,
                'is_available_for_booking' => 1,
            ],
            
            // Amenities
            [
                'facility_type_id' => $amenities->id,
                'name' => 'Spa and Massage',
                'quantity' => 1,
                'expected_capacity' => 4,
                'max_capacity' => 6,
                'description' => 'Relaxation and wellness services',
                'is_maintenance' => 0,
                'is_available_for_booking' => 1,
            ],
            [
                'facility_type_id' => $amenities->id,
                'name' => 'Game Room',
                'quantity' => 1,
                'expected_capacity' => 15,
                'max_capacity' => 20,
                'description' => 'Indoor games and entertainment area',
                'is_maintenance' => 0,
                'is_available_for_booking' => 1,
            ],
            
            // Dining
            [
                'facility_type_id' => $dining->id,
                'name' => 'Main Restaurant',
                'quantity' => 1,
                'expected_capacity' => 40,
                'max_capacity' => 60,
                'description' => 'Full-service restaurant and bar',
                'is_maintenance' => 0,
                'is_available_for_booking' => 1,
            ],
        ];

        foreach ($facilities as $facility) {
            Facility::create($facility);
        }

        $this->command->info('Facilities created successfully!');
    }
}