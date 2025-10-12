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
            Permission::create(['name' => $permission, 'guard_name' => 'web']);
        }

        // Create roles and assign permissions
        
        // Admin Role - Full Access
        $admin = Role::create(['name' => 'Admin', 'guard_name' => 'web']);
        $admin->givePermissionTo(Permission::all());

        // Manager Role - Most Access
        $manager = Role::create(['name' => 'Manager', 'guard_name' => 'web']);
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

        // Staff Role - Operational Access
        $staff = Role::create(['name' => 'Staff', 'guard_name' => 'web']);
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

        $this->command->info('Roles and Permissions created successfully!');
    }
}
