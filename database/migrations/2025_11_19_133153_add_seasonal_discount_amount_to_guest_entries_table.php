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
            // Add seasonal discount amount field to track the discount value
            $table->decimal('seasonal_discount_amount', 10, 2)
                ->default(0.00)
                ->after('seasonal_discount_id')
                ->comment('Amount of seasonal discount applied');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guest_entries', function (Blueprint $table) {
            $table->dropColumn('seasonal_discount_amount');
        });
    }
};
