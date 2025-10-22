<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Add composite index for availability checking
            // This dramatically improves performance when checking booking conflicts
            $table->index(
                ['facility_id', 'booking_status', 'check_in_date', 'check_out_date'],
                'bookings_availability_check_idx'
            );
        });

        Schema::table('guest_entries', function (Blueprint $table) {
            // Add index for checking if entry is checked out
            // This speeds up queries filtering active facility rentals
            $table->index('is_checked_out', 'guest_entries_checkout_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('bookings_availability_check_idx');
        });

        Schema::table('guest_entries', function (Blueprint $table) {
            $table->dropIndex('guest_entries_checkout_status_idx');
        });
    }
};