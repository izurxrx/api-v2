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
        Schema::table('booking_guest_discounts', function (Blueprint $table) {
            // Make discount calculation fields nullable since they're calculated by the API, not set directly
            if (Schema::hasColumn('booking_guest_discounts', 'discount_per_guest')) {
                $table->decimal('discount_per_guest', 10, 2)->nullable()->default(0)->change();
            }
            if (Schema::hasColumn('booking_guest_discounts', 'total_discount')) {
                $table->decimal('total_discount', 10, 2)->nullable()->default(0)->change();
            }
            if (Schema::hasColumn('booking_guest_discounts', 'discount_amount')) {
                $table->decimal('discount_amount', 10, 2)->nullable()->default(0)->change();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('booking_guest_discounts', function (Blueprint $table) {
            if (Schema::hasColumn('booking_guest_discounts', 'discount_per_guest')) {
                $table->decimal('discount_per_guest', 10, 2)->nullable(false)->change();
            }
            if (Schema::hasColumn('booking_guest_discounts', 'total_discount')) {
                $table->decimal('total_discount', 10, 2)->nullable(false)->change();
            }
        });
    }
};
