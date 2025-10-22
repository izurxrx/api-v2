<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Add facility_subtotal after guest_breakdown
            if (!Schema::hasColumn('bookings', 'facility_subtotal')) {
                $table->decimal('facility_subtotal', 10, 2)->default(0)->after('guest_breakdown');
            }
            
            // Add special_requests after notes
            if (!Schema::hasColumn('bookings', 'special_requests')) {
                $table->text('special_requests')->nullable()->after('notes');
            }
            
            // Add cancellation_reason after notes
            if (!Schema::hasColumn('bookings', 'cancellation_reason')) {
                $table->text('cancellation_reason')->nullable()->after('special_requests');
            }
        });
        
        // Copy subtotal to facility_subtotal for existing records
        DB::statement("
            UPDATE bookings 
            SET facility_subtotal = subtotal
            WHERE facility_subtotal = 0 AND subtotal > 0
        ");
        
        // Update booking_status enum to include No_Show
        DB::statement("
            ALTER TABLE bookings 
            CHANGE booking_status booking_status 
            ENUM('Pending', 'Confirmed', 'Checked_In', 'Checked_Out', 'Cancelled', 'No_Show') 
            NOT NULL DEFAULT 'Pending'
        ");
    }

    public function down(): void
    {
        // Revert enum first
        DB::statement("
            ALTER TABLE bookings 
            CHANGE booking_status booking_status 
            ENUM('Pending', 'Confirmed', 'Checked_In', 'Checked_Out', 'Cancelled') 
            NOT NULL DEFAULT 'Pending'
        ");
        
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'facility_subtotal')) {
                $table->dropColumn('facility_subtotal');
            }
            if (Schema::hasColumn('bookings', 'special_requests')) {
                $table->dropColumn('special_requests');
            }
            if (Schema::hasColumn('bookings', 'cancellation_reason')) {
                $table->dropColumn('cancellation_reason');
            }
        });
    }
};