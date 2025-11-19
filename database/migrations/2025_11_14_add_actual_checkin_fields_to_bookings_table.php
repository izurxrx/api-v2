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
            // Add actual check-in datetime
            if (!Schema::hasColumn('bookings', 'actual_check_in_datetime')) {
                $table->dateTime('actual_check_in_datetime')->nullable()->after('check_in_datetime')
                    ->comment('Actual check-in time (vs scheduled check_in_datetime)');
            }
            
            // Add actual check-out datetime
            if (!Schema::hasColumn('bookings', 'actual_check_out_datetime')) {
                $table->dateTime('actual_check_out_datetime')->nullable()->after('check_out_datetime')
                    ->comment('Actual check-out time (vs scheduled check_out_datetime)');
            }
            
            // Add actual number of guests
            if (!Schema::hasColumn('bookings', 'actual_guests')) {
                $table->integer('actual_guests')->nullable()->after('number_of_guests')
                    ->comment('Actual number of guests who showed up (vs number_of_guests booked)');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            if (Schema::hasColumn('bookings', 'actual_check_in_datetime')) {
                $table->dropColumn('actual_check_in_datetime');
            }
            if (Schema::hasColumn('bookings', 'actual_check_out_datetime')) {
                $table->dropColumn('actual_check_out_datetime');
            }
            if (Schema::hasColumn('bookings', 'actual_guests')) {
                $table->dropColumn('actual_guests');
            }
        });
    }
};
