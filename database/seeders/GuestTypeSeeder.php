<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\GuestType;
use App\Models\Discount;

class GuestTypeSeeder extends Seeder
{
    public function run(): void
    {
        $childDiscount = Discount::where('name', 'Child Discount')->first();
        $seniorDiscount = Discount::where('name', 'Senior Citizen Discount')->first();
        $pwdDiscount = Discount::where('name', 'PWD Discount')->first();

        $guestTypes = [
            [
                'name' => 'Adult',
                'description' => 'Regular adult guest (18-59 years old)',
                'default_discount_id' => null,
            ],
            [
                'name' => 'Child',
                'description' => 'Child guest (12 years old and below)',
                'default_discount_id' => $childDiscount->id,
            ],
            [
                'name' => 'Senior',
                'description' => 'Senior citizen (60 years old and above)',
                'default_discount_id' => $seniorDiscount->id,
            ],
            [
                'name' => 'PWD',
                'description' => 'Person with disability',
                'default_discount_id' => $pwdDiscount->id,
            ],
            [
                'name' => 'Infant',
                'description' => 'Infant (2 years old and below) - Free',
                'default_discount_id' => null,
            ],
        ];

        foreach ($guestTypes as $guestType) {
            GuestType::create($guestType);
        }

        $this->command->info('Guest Types created successfully!');
    }
}