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
            // Add entrance_rate_id for Swimming bookings
            // NULL for Package bookings
            $table->unsignedBigInteger('entrance_rate_id')->nullable()->after('booking_type');
            
            $table->foreign('entrance_rate_id')
                ->references('id')
                ->on('rates')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['entrance_rate_id']);
            $table->dropColumn('entrance_rate_id');
        });
    }
};