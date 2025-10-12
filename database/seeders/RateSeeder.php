<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Rate;
use App\Models\Facility;

class RateSeeder extends Seeder
{
    public function run(): void
    {
        // Entrance Rates (No facility_id)
        $entranceRates = [
            [
                'facility_id' => null,
                'rate_name' => 'Adult Entrance Fee',
                'rate_category' => 'Entrance',
                'rate_type' => null,
                'base_price' => 200.00,
                'duration' => null,
                'extension_fee' => 0.00,
            ],
            [
                'facility_id' => null,
                'rate_name' => 'Weekend Adult Entrance',
                'rate_category' => 'Entrance',
                'rate_type' => null,
                'base_price' => 250.00,
                'duration' => null,
                'extension_fee' => 0.00,
            ],
        ];

        foreach ($entranceRates as $rate) {
            Rate::create($rate);
        }

        // Facility Rates
        $facilities = Facility::all();
        
        foreach ($facilities as $facility) {
            if (in_array($facility->name, ['Standard Room', 'Deluxe Room', 'Family Suite', 'VIP Villa'])) {
                // Day-based rates for rooms
                Rate::create([
                    'facility_id' => $facility->id,
                    'rate_name' => $facility->name . ' - Daily Rate',
                    'rate_category' => 'Facility',
                    'rate_type' => 'Day_Based',
                    'base_price' => $this->getRoomPrice($facility->name),
                    'duration' => 24,
                    'extension_fee' => $this->getRoomPrice($facility->name) * 0.2,
                ]);
            } else {
                // Time-based rates for other facilities
                Rate::create([
                    'facility_id' => $facility->id,
                    'rate_name' => $facility->name . ' - Hourly Rate',
                    'rate_category' => 'Facility',
                    'rate_type' => 'Time_Based',
                    'base_price' => $this->getFacilityPrice($facility->name),
                    'duration' => $this->getFacilityDuration($facility->name),
                    'extension_fee' => $this->getFacilityPrice($facility->name) * 0.5,
                ]);
            }
        }

        $this->command->info('Rates created successfully!');
    }

    private function getRoomPrice($roomName)
    {
        return match($roomName) {
            'Standard Room' => 2500.00,
            'Deluxe Room' => 3500.00,
            'Family Suite' => 5000.00,
            'VIP Villa' => 8000.00,
            default => 2000.00,
        };
    }

    private function getFacilityPrice($facilityName)
    {
        return match($facilityName) {
            'Main Swimming Pool' => 1500.00,
            'Kids Pool' => 800.00,
            'Jacuzzi' => 300.00,
            'Function Hall A' => 8000.00,
            'Conference Room' => 500.00,
            'Garden Pavilion' => 3000.00,
            'Spa and Massage' => 800.00,
            'Game Room' => 200.00,
            'Main Restaurant' => 0.00, // Pay per order
            default => 500.00,
        };
    }

    private function getFacilityDuration($facilityName)
    {
        return match($facilityName) {
            'Main Swimming Pool' => 8,
            'Kids Pool' => 8,
            'Jacuzzi' => 1,
            'Function Hall A' => 12,
            'Conference Room' => 1,
            'Garden Pavilion' => 8,
            'Spa and Massage' => 1,
            'Game Room' => 1,
            default => 1,
        };
    }
}