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
        Schema::table('guest_entries', function (Blueprint $table) {
            // Add booking relationship
            $table->foreignId('booking_id')->nullable()->after('id')->constrained('bookings')->onDelete('set null');
            
            // Add entry type to differentiate walk-ins from booking check-ins
            $table->enum('entry_type', ['walk_in', 'booking'])->default('walk_in')->after('booking_id');
            
            // Add index for better query performance
            $table->index('booking_id');
            $table->index('entry_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guest_entries', function (Blueprint $table) {
            // Drop indexes first
            $table->dropIndex(['booking_id']);
            $table->dropIndex(['entry_type']);
            
            // Drop columns
            $table->dropForeign(['booking_id']);
            $table->dropColumn(['booking_id', 'entry_type']);
        });
    }
};
