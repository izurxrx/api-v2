<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\DB;

class RoleAndPermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // ========================================
        // STEP 1: Clean up duplicates and fix guards
        // ========================================
        $this->cleanupDuplicatesAndFixGuards();

        // ========================================
        // STEP 2: Create/Update permissions
        // ========================================
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
            'cancel-bookings',              // ✅ NEW
            'record-downpayment',           // ✅ NEW

            // Guest Monitoring (Walk-ins)
            'process-walk-ins',
            'view-walk-ins',
            'checkout-walk-ins',

            // Billing
            'view-billings',                // ✅ NEW
            'manage-billings',              // ✅ NEW
            'cancel-billings',              // ✅ NEW
            'void-billings',                // ✅ NEW

            // Payments
            'process-payments',
            'view-payments',
            'reverse-payments',             // ✅ NEW

            // Facilities
            'manage-facilities',
            'view-facilities',
            'check-availability',           // ✅ NEW

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
            'export-reports',               // ✅ NEW

            // System
            'override-restrictions',
        ];

        foreach ($permissions as $permissionName) {
            Permission::firstOrCreate(
                ['name' => $permissionName, 'guard_name' => 'api']
            );
        }

        $this->command->info('✅ Permissions created/updated');

        // ========================================
        // STEP 3: Create/Update roles
        // ========================================

        // ADMIN - Full access
        $admin = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'api']);
        $admin->syncPermissions(Permission::where('guard_name', 'api')->get());

        // MANAGER - Operations + reversals
        $manager = Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'api']);
        $manager->syncPermissions([
            'view-users',
            'manage-staff-users',
            'manage-bookings',
            'view-bookings',
            'check-in-guests',
            'cancel-bookings',
            'record-downpayment',
            'process-walk-ins',
            'view-walk-ins',
            'checkout-walk-ins',
            'view-billings',
            'manage-billings',
            'cancel-billings',
            'process-payments',
            'view-payments',
            'reverse-payments',
            'manage-facilities',
            'view-facilities',
            'check-availability',
            'manage-rates',
            'view-rates',
            'manage-discounts',
            'view-discounts',
            'view-financial-reports',
            'generate-reports',
            'view-audit-logs',
            'export-reports',
            'override-restrictions',
        ]);

        // STAFF - Front desk only
        $staff = Role::firstOrCreate(['name' => 'Staff', 'guard_name' => 'api']);
        $staff->syncPermissions([
            'view-bookings',
            'check-in-guests',
            'record-downpayment',
            'process-walk-ins',
            'view-walk-ins',
            'checkout-walk-ins',
            'view-billings',
            'process-payments',
            'view-payments',
            'view-facilities',
            'check-availability',
            'view-rates',
            'view-discounts',
        ]);

        $this->command->info('✅ Roles created/updated successfully!');
        $this->command->info('   - Admin: Full access');
        $this->command->info('   - Manager: Operations + Payment reversal');
        $this->command->info('   - Staff: Front desk operations');
        
        // Clear cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    }

    /**
     * Clean up duplicates and fix guard names
     */
    private function cleanupDuplicatesAndFixGuards(): void
    {
        $this->command->info('🧹 Cleaning up permissions and roles...');

        DB::transaction(function () {
            // Get all permissions with 'web' guard
            $webPermissions = DB::table('permissions')
                ->where('guard_name', 'web')
                ->get();

            foreach ($webPermissions as $webPerm) {
                // Check if an 'api' version already exists
                $apiExists = DB::table('permissions')
                    ->where('name', $webPerm->name)
                    ->where('guard_name', 'api')
                    ->exists();

                if ($apiExists) {
                    // Delete the 'web' version (keep 'api')
                    $this->command->warn("   ⚠️  Duplicate found: {$webPerm->name} - removing 'web' version");
                    
                    // First, remove from pivot tables
                    DB::table('role_has_permissions')->where('permission_id', $webPerm->id)->delete();
                    DB::table('model_has_permissions')->where('permission_id', $webPerm->id)->delete();
                    
                    // Then delete the permission
                    DB::table('permissions')->where('id', $webPerm->id)->delete();
                } else {
                    // No duplicate, just update the guard
                    $this->command->info("   ✓ Converting {$webPerm->name} from 'web' to 'api'");
                    DB::table('permissions')
                        ->where('id', $webPerm->id)
                        ->update(['guard_name' => 'api']);
                }
            }

            // Same for roles
            $webRoles = DB::table('roles')
                ->where('guard_name', 'web')
                ->get();

            foreach ($webRoles as $webRole) {
                $apiExists = DB::table('roles')
                    ->where('name', $webRole->name)
                    ->where('guard_name', 'api')
                    ->exists();

                if ($apiExists) {
                    $this->command->warn("   ⚠️  Duplicate role: {$webRole->name} - removing 'web' version");
                    
                    // Remove from pivot tables
                    DB::table('role_has_permissions')->where('role_id', $webRole->id)->delete();
                    DB::table('model_has_roles')->where('role_id', $webRole->id)->delete();
                    
                    // Delete the role
                    DB::table('roles')->where('id', $webRole->id)->delete();
                } else {
                    $this->command->info("   ✓ Converting role {$webRole->name} from 'web' to 'api'");
                    DB::table('roles')
                        ->where('id', $webRole->id)
                        ->update(['guard_name' => 'api']);
                }
            }

            $this->command->info('✅ Cleanup completed');
        });
    }
}