<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Discount;

class DiscountSeeder extends Seeder
{
    public function run(): void
    {
        $discounts = [
            // Guest Type Discounts (Automatic)
            [
                'name' => 'Child Discount',
                'description' => 'Automatic discount for children 12 years old and below',
                'category' => 'Direct_Discount',
                'type' => 'Percentage',
                'value' => 40.00,
                'is_guest_type_discount' => 1,
                'valid_from' => null,
                'valid_until' => null,
            ],
            [
                'name' => 'Senior Citizen Discount',
                'description' => 'Automatic discount for senior citizens 60 years and above',
                'category' => 'Direct_Discount',
                'type' => 'Percentage',
                'value' => 20.00,
                'is_guest_type_discount' => 1,
                'valid_from' => null,
                'valid_until' => null,
            ],
            [
                'name' => 'PWD Discount',
                'description' => 'Automatic discount for persons with disability',
                'category' => 'Direct_Discount',
                'type' => 'Percentage',
                'value' => 20.00,
                'is_guest_type_discount' => 1,
                'valid_from' => null,
                'valid_until' => null,
            ],
            
            // Manual Discounts
            [
                'name' => 'Group Discount',
                'description' => 'Discount for groups of 10 or more',
                'category' => 'Direct_Discount',
                'type' => 'Percentage',
                'value' => 10.00,
                'is_guest_type_discount' => 0,
                'valid_from' => null,
                'valid_until' => null,
            ],
            [
                'name' => 'Early Bird Special',
                'description' => 'Early check-in discount',
                'category' => 'Seasonal_Discount',
                'type' => 'Percentage',
                'value' => 15.00,
                'is_guest_type_discount' => 0,
                'valid_from' => null,
                'valid_until' => null,
            ],
            [
                'name' => 'Weekend Surcharge',
                'description' => 'Additional charge for weekend bookings',
                'category' => 'Seasonal_Discount',
                'type' => 'Fixed_Amount',
                'value' => 200.00,
                'is_guest_type_discount' => 0,
                'valid_from' => null,
                'valid_until' => null,
            ],
            [
                'name' => 'Holiday Promo',
                'description' => 'Holiday season discount',
                'category' => 'Seasonal_Discount',
                'type' => 'Percentage',
                'value' => 15.00,
                'is_guest_type_discount' => 0,
                'valid_from' => now()->startOfMonth(),
                'valid_until' => now()->endOfMonth()->addMonth(),
            ],
        ];

        foreach ($discounts as $discount) {
            Discount::create($discount);
        }

        $this->command->info('Discounts created successfully!');
    }
}
