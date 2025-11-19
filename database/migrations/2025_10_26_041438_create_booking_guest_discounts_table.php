<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This table tracks per-guest discounts for Direct discounts
     * Example: 12 total guests, 2 are seniors, 1 is child
     * Creates rows for each discount type applied
     */
    public function up(): void
    {
        Schema::create('booking_guest_discounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('booking_id');
            $table->string('guest_type'); // Guest type: senior, pwd, child
            $table->unsignedBigInteger('discount_id'); // Direct discount (Senior, PWD, Child)
            $table->integer('guest_count'); // Number of guests with this discount
            $table->decimal('discount_per_guest', 10, 2)->nullable(); // Discount amount per guest
            $table->decimal('total_discount', 10, 2)->nullable(); // guest_count × discount_per_guest
            $table->timestamps();
            
            $table->foreign('booking_id')
                ->references('id')
                ->on('bookings')
                ->onDelete('cascade');
            
            $table->foreign('discount_id')
                ->references('id')
                ->on('discounts')
                ->onDelete('cascade');
            
            $table->index(['booking_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_guest_discounts');
    }
};