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
        Schema::table('guest_entry_facilities', function (Blueprint $table) {
            $table->dateTime('start_datetime')->nullable()->change();
            $table->dateTime('end_datetime')->nullable()->change();
            $table->integer('duration_hours')->nullable()->change();
            $table->decimal('base_amount', 10, 2)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guest_entry_facilities', function (Blueprint $table) {
            $table->dateTime('start_datetime')->nullable(false)->change();
            $table->dateTime('end_datetime')->nullable(false)->change();
            $table->integer('duration_hours')->nullable(false)->change();
            $table->decimal('base_amount', 10, 2)->nullable(false)->change();
        });
    }
};
