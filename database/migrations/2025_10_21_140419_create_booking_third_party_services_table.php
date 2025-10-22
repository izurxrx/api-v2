<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('booking_third_party_services')) {
            Schema::create('booking_third_party_services', function (Blueprint $table) {
                $table->id();
                $table->foreignId('booking_id')->constrained('bookings')->onDelete('cascade');
                $table->string('service_name', 100);
                $table->decimal('amount', 10, 2);
                $table->timestamps();
                
                // Indexes
                $table->index('booking_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_third_party_services');
    }
};