<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions
        $permissions = [
            // User Management
            'manage-users',
            'view-users',
            'manage-staff-users',
            'manage-manager-users',
            'manage-admin-users',

            // Booking Management
            'manage-bookings',
            'view-bookings',
            'check-in-guests',

            // Guest Monitoring (Walk-ins)
            'process-walk-ins',
            'view-walk-ins',
            'checkout-walk-ins',

            // Payments
            'process-payments',
            'view-payments',

            // Facilities
            'manage-facilities',
            'view-facilities',

            // Rates
            'manage-rates',
            'view-rates',

            // Discounts
            'manage-discounts',
            'view-discounts',

            // Reports
            'view-financial-reports',
            'generate-reports',
            'view-audit-logs',

            // System
            'override-restrictions',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }

        // Create roles and assign permissions
        $admin = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'api']);
        $admin->givePermissionTo(Permission::all());

        $manager = Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'api']);
        $manager->givePermissionTo([
            'view-users',
            'manage-staff-users',
            'manage-bookings',
            'view-bookings',
            'check-in-guests',
            'process-walk-ins',
            'view-walk-ins',
            'checkout-walk-ins',
            'process-payments',
            'view-payments',
            'manage-facilities',
            'view-facilities',
            'manage-rates',
            'view-rates',
            'manage-discounts',
            'view-discounts',
            'view-financial-reports',
            'generate-reports',
            'view-audit-logs',
            'override-restrictions',
        ]);

        $staff = Role::firstOrCreate(['name' => 'Staff', 'guard_name' => 'api']);
        $staff->givePermissionTo([
            'view-bookings',
            'check-in-guests',
            'process-walk-ins',
            'view-walk-ins',
            'checkout-walk-ins',
            'process-payments',
            'view-payments',
            'view-facilities',
            'view-rates',
            'view-discounts',
        ]);

        $this->command->info('✅ Roles and Permissions created successfully!');
    }
}
