<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds guest_entry_id to billings table to link billing to guest entry when booking is checked in.
     * This allows the same billing to be accessed from both booking and guest entry.
     */
    public function up(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            // Add guest_entry_id to link billing to guest entry (when created from booking check-in)
            $table->unsignedBigInteger('guest_entry_id')->nullable()->after('billable_id');

            // Add foreign key constraint
            $table->foreign('guest_entry_id')
                  ->references('id')
                  ->on('guest_entries')
                  ->onDelete('set null');

            // Add index for faster lookups
            $table->index('guest_entry_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            // Drop foreign key first
            $table->dropForeign(['guest_entry_id']);

            // Drop column
            $table->dropColumn('guest_entry_id');
        });
    }
};
