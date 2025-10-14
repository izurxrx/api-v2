<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleAndPermissionSeeder::class,
            UserSeeder::class,
            DiscountSeeder::class,
            GuestTypeSeeder::class,
            FacilityTypeSeeder::class,
            FacilitySeeder::class,
            RateSeeder::class,
            
        ]);
    }
}
