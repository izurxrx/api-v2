<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration to add missing fields for proper Swimming vs Package booking implementation
 * as per CORRECTED_Final_Bookings_Specification.md
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // ==========================================
        // UPDATE BOOKINGS TABLE
        // ==========================================
        Schema::table('bookings', function (Blueprint $table) {
            // Add missing fields if they don't exist
            
            // Entrance subtotal (for Swimming bookings)
            if (!Schema::hasColumn('bookings', 'entrance_subtotal')) {
                $table->decimal('entrance_subtotal', 10, 2)->default(0.00)->after('facility_subtotal')
                    ->comment('Entrance fee subtotal before discounts (Swimming bookings only)');
            }
            
            // Overstay amount (for Package bookings)
            if (!Schema::hasColumn('bookings', 'overstay_amount')) {
                $table->decimal('overstay_amount', 10, 2)->default(0.00)->after('discount_amount')
                    ->comment('Overstay fees for Package bookings (based on extension_fee)');
            }
            
            // Add index on booking_type for performance
            if (!Schema::hasIndex('bookings', 'bookings_booking_type_index')) {
                $table->index('booking_type');
            }
            
            // Add index on entrance_rate_id
            if (!Schema::hasIndex('bookings', 'bookings_entrance_rate_id_index')) {
                $table->index('entrance_rate_id');
            }
        });
        
        // ==========================================
        // CREATE BOOKING_GUEST_DISCOUNTS TABLE
        // ==========================================
        if (!Schema::hasTable('booking_guest_discounts')) {
            Schema::create('booking_guest_discounts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('booking_id')->constrained()->onDelete('cascade');
                $table->foreignId('discount_id')->constrained()->onDelete('restrict');
                $table->string('guest_type', 50)->comment('senior, pwd, child');
                $table->integer('guest_count')->default(1);
                $table->decimal('discount_amount', 10, 2)->default(0.00)
                    ->comment('Calculated discount amount for this guest group');
                $table->timestamps();
                
                // Indexes
                $table->index(['booking_id', 'guest_type']);
            });
        }
        
        // ==========================================
        // UPDATE GUEST_ENTRIES TABLE
        // ==========================================
        Schema::table('guest_entries', function (Blueprint $table) {
            // Add entrance subtotal
            if (!Schema::hasColumn('guest_entries', 'entrance_subtotal')) {
                $table->decimal('entrance_subtotal', 10, 2)->default(0.00)->after('subtotal')
                    ->comment('Entrance fee subtotal before discounts');
            }
            
            // Add facility subtotal
            if (!Schema::hasColumn('guest_entries', 'facility_subtotal')) {
                $table->decimal('facility_subtotal', 10, 2)->default(0.00)->after('entrance_subtotal')
                    ->comment('Facility (cottages) subtotal');
            }
            
            // Add seasonal discount ID
            if (!Schema::hasColumn('guest_entries', 'seasonal_discount_id')) {
                $table->foreignId('seasonal_discount_id')->nullable()->after('discount_id')
                    ->constrained('discounts')->onDelete('set null')
                    ->comment('Seasonal discount applied to total entrance');
            }
        });
        
        // ==========================================
        // UPDATE BILLINGS TABLE
        // ==========================================
        Schema::table('billings', function (Blueprint $table) {
            // Add overstay amount tracking
            if (!Schema::hasColumn('billings', 'overstay_amount')) {
                $table->decimal('overstay_amount', 10, 2)->default(0.00)->after('discount_amount')
                    ->comment('Overstay fees applied at checkout (Package bookings only)');
            }
        });
        
        // ==========================================
        // ADD CHECK CONSTRAINTS (if using MySQL 8.0+)
        // ==========================================
        if (DB::connection()->getDriverName() === 'mysql') {
            // Ensure Swimming bookings have entrance_rate_id
            DB::statement("
                ALTER TABLE bookings 
                ADD CONSTRAINT chk_swimming_has_entrance 
                CHECK (
                    booking_type != 'Swimming' OR entrance_rate_id IS NOT NULL
                )
            ");
            
            // Ensure Package bookings don't have entrance_rate_id
            DB::statement("
                ALTER TABLE bookings 
                ADD CONSTRAINT chk_package_no_entrance 
                CHECK (
                    booking_type != 'Package' OR entrance_rate_id IS NULL
                )
            ");
            
            // Ensure discount stacking rules
            DB::statement("
                ALTER TABLE bookings 
                ADD CONSTRAINT chk_discount_stacking 
                CHECK (
                    discount_mode IN ('None', 'Direct', 'Seasonal', 'Manual')
                )
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop check constraints if they exist
        if (DB::connection()->getDriverName() === 'mysql') {
            try {
                DB::statement("ALTER TABLE bookings DROP CONSTRAINT IF EXISTS chk_swimming_has_entrance");
                DB::statement("ALTER TABLE bookings DROP CONSTRAINT IF EXISTS chk_package_no_entrance");
                DB::statement("ALTER TABLE bookings DROP CONSTRAINT IF EXISTS chk_discount_stacking");
            } catch (\Exception $e) {
                // Constraints might not exist
            }
        }
        
        // Drop booking_guest_discounts table
        Schema::dropIfExists('booking_guest_discounts');
        
        // Remove added columns
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['entrance_subtotal', 'overstay_amount']);
        });
        
        Schema::table('guest_entries', function (Blueprint $table) {
            $table->dropColumn(['entrance_subtotal', 'facility_subtotal']);
            $table->dropForeign(['seasonal_discount_id']);
            $table->dropColumn('seasonal_discount_id');
        });
        
        Schema::table('billings', function (Blueprint $table) {
            $table->dropColumn('overstay_amount');
        });
    }
};