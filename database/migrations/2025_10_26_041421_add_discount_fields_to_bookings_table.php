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
            // Discount tracking fields
            $table->enum('discount_mode', ['None', 'Direct', 'Seasonal', 'Manual'])
                ->default('None')
                ->after('total_amount');
            
            // For Direct and Seasonal discounts (links to discounts table)
            $table->unsignedBigInteger('discount_id')->nullable()->after('discount_mode');
            
            // For Manual discount (staff-entered amount)
            $table->decimal('manual_discount_amount', 10, 2)->default(0.00)->after('discount_id');
            
            // Total discount applied
            $table->decimal('discount_amount', 10, 2)->default(0.00)->after('manual_discount_amount');
            
            $table->foreign('discount_id')
                ->references('id')
                ->on('discounts')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['discount_id']);
            $table->dropColumn([
                'discount_mode',
                'discount_id',
                'manual_discount_amount',
                'discount_amount'
            ]);
        });
    }
};