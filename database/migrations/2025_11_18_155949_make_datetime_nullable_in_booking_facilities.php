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
        Schema::table('booking_facilities', function (Blueprint $table) {
            $table->datetime('start_datetime')->nullable()->change();
            $table->datetime('end_datetime')->nullable()->change();
            $table->integer('duration_hours')->nullable()->change();
            $table->decimal('base_amount', 10, 2)->nullable()->change();
            
            // Add columns that might be missing
            if (!Schema::hasColumn('booking_facilities', 'rate_amount')) {
                $table->decimal('rate_amount', 10, 2)->default(0)->after('rate_id');
            }
            if (!Schema::hasColumn('booking_facilities', 'subtotal')) {
                $table->decimal('subtotal', 10, 2)->default(0)->after('quantity');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('booking_facilities', function (Blueprint $table) {
            $table->datetime('start_datetime')->nullable(false)->change();
            $table->datetime('end_datetime')->nullable(false)->change();
            $table->integer('duration_hours')->nullable(false)->change();
            $table->decimal('base_amount', 10, 2)->nullable(false)->change();
        });
    }
};
