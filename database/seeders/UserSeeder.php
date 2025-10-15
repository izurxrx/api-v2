<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Admin User
        $admin = User::firstOrCreate([
            'username' => 'admin',
        ], [
            'full_name' => 'System Administrator',
            'contact_no' => '+63 917 123 4567',
            'password' => Hash::make('admin123'),
        ]);
        $admin->assignRole('Admin');

        // Manager User
        $manager = User::firstOrCreate([
            'username' => 'manager',
        ], [
            'full_name' => 'Resort Manager',
            'contact_no' => '+63 918 234 5678',
            'password' => Hash::make('manager123'),
        ]);
        $manager->assignRole('Manager');

        // Staff Users
        $staff1 = User::firstOrCreate([
            'username' => 'staff1',
        ], [
            'full_name' => 'Front Desk Staff 1',
            'contact_no' => '+63 919 345 6789',
            'password' => Hash::make('staff123'),
        ]);
        $staff1->assignRole('Staff');

        $staff2 = User::firstOrCreate([
            'username' => 'staff2',
        ], [
            'full_name' => 'Front Desk Staff 2',
            'contact_no' => '+63 920 456 7890',
            'password' => Hash::make('staff123'),
        ]);
        $staff2->assignRole('Staff');

        $this->command->info('✅ Users created successfully!');
        $this->command->info('Admin - admin / admin123');
        $this->command->info('Manager - manager / manager123');
        $this->command->info('Staff - staff1/staff2 / staff123');
    }
}
