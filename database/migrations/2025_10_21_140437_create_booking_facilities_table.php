<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_facilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('bookings')->onDelete('cascade');
            $table->foreignId('facility_id')->constrained('facilities')->onDelete('cascade');
            $table->foreignId('rate_id')->nullable()->constrained('rates')->onDelete('set null');
            $table->datetime('start_datetime');
            $table->datetime('end_datetime');
            $table->integer('duration_hours');
            $table->decimal('base_amount', 10, 2);
            $table->integer('quantity')->default(1)->comment('Number of units of this facility');
            $table->timestamps();
            
            // Indexes
            $table->index('booking_id');
            $table->index('facility_id');
            $table->index('rate_id');
            $table->index(['facility_id', 'start_datetime', 'end_datetime']);
        });
        
        // Migrate existing bookings to booking_facilities
        DB::statement("
            INSERT INTO booking_facilities 
                (booking_id, facility_id, rate_id, start_datetime, end_datetime, duration_hours, base_amount, quantity, created_at, updated_at)
            SELECT 
                id as booking_id,
                facility_id,
                NULL as rate_id,
                check_in_datetime as start_datetime,
                check_out_datetime as end_datetime,
                duration_hours,
                facility_subtotal as base_amount,
                1 as quantity,
                created_at,
                updated_at
            FROM bookings
            WHERE facility_id IS NOT NULL
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_facilities');
    }
};