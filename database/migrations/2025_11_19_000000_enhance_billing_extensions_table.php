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
        Schema::table('billing_extensions', function (Blueprint $table) {
            // Add foreign keys for tracking
            $table->foreignId('facility_id')->nullable()->after('billing_id')->constrained('facilities');
            $table->foreignId('rate_id')->nullable()->after('facility_id')->constrained('rates');
            $table->foreignId('discount_id')->nullable()->after('rate_id')->constrained('discounts');
            
            // Add overtime-specific fields
            $table->decimal('hours', 10, 2)->nullable()->after('quantity'); // Overtime hours
            $table->decimal('discount_amount', 10, 2)->default(0)->after('amount'); // Discount on overtime
            $table->boolean('is_overtime')->default(false)->after('extension_type'); // Flag for overtime charges
            
            // Add time tracking for facilities with extensions
            $table->datetime('facility_start_datetime')->nullable()->after('description');
            $table->datetime('facility_end_datetime')->nullable()->after('facility_start_datetime');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('billing_extensions', function (Blueprint $table) {
            $table->dropForeign(['facility_id']);
            $table->dropForeign(['rate_id']);
            $table->dropForeign(['discount_id']);
            $table->dropColumn([
                'facility_id',
                'rate_id',
                'discount_id',
                'hours',
                'discount_amount',
                'is_overtime',
                'facility_start_datetime',
                'facility_end_datetime',
            ]);
        });
    }
};
