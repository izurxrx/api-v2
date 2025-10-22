<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Add new fields
            $table->string('email', 100)->nullable()->after('contact_number');
            $table->string('id_type', 50)->nullable()->after('email');
            $table->string('id_number', 50)->nullable()->after('id_type');
            
            // Add combined datetime fields (keep old fields for now for backward compatibility)
            $table->datetime('check_in_datetime')->nullable()->after('check_out_date');
            $table->datetime('check_out_datetime')->nullable()->after('check_in_datetime');
            $table->integer('duration_hours')->nullable()->after('check_out_datetime');
            
            // Add cancellation fields
            $table->datetime('cancellation_deadline')->nullable()->after('booking_status')
                  ->comment('72 hours before check-in');
            $table->text('cancellation_reason')->nullable()->after('cancelled_at');
            $table->string('special_requests')->nullable()->after('notes');
            
            // Add facility subtotal (rename from subtotal to facility_subtotal for clarity)
            $table->decimal('facility_subtotal', 10, 2)->default(0)->after('booking_status');
            
            // Update booking_status enum to include No_Show
            $table->enum('booking_status', [
                'Pending', 'Confirmed', 'Checked_In', 'Checked_Out', 'Cancelled', 'No_Show'
            ])->default('Pending')->change();
            
            // Add indexes for new fields
            $table->index('check_in_datetime');
            $table->index('check_out_datetime');
            $table->index('email');
        });
        
        // Migrate existing data to new datetime fields
        DB::statement("
            UPDATE bookings 
            SET check_in_datetime = CONCAT(check_in_date, ' ', COALESCE(check_in_time, '14:00:00')),
                check_out_datetime = CONCAT(check_out_date, ' ', COALESCE(check_out_time, '12:00:00'))
            WHERE check_in_datetime IS NULL
        ");
        
        // Calculate duration_hours for existing bookings
        DB::statement("
            UPDATE bookings 
            SET duration_hours = TIMESTAMPDIFF(HOUR, check_in_datetime, check_out_datetime)
            WHERE duration_hours IS NULL
        ");
        
        // Calculate cancellation_deadline (72 hours before check-in)
        DB::statement("
            UPDATE bookings 
            SET cancellation_deadline = DATE_SUB(check_in_datetime, INTERVAL 72 HOUR)
            WHERE cancellation_deadline IS NULL 
            AND booking_status NOT IN ('Cancelled', 'Checked_Out')
        ");
        
        // Copy subtotal to facility_subtotal
        DB::statement("
            UPDATE bookings 
            SET facility_subtotal = subtotal
            WHERE facility_subtotal = 0
        ");
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn([
                'email',
                'id_type',
                'id_number',
                'check_in_datetime',
                'check_out_datetime',
                'duration_hours',
                'cancellation_deadline',
                'cancellation_reason',
                'special_requests',
                'facility_subtotal'
            ]);
            
            // Revert booking_status enum
            $table->enum('booking_status', [
                'Pending', 'Confirmed', 'Checked_In', 'Checked_Out', 'Cancelled'
            ])->default('Pending')->change();
        });
    }
};