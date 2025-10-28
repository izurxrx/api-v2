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
            // Add checked_out_by foreign key to users table
            $table->unsignedBigInteger('checked_out_by')->nullable()->after('created_by');
            $table->foreign('checked_out_by')->references('id')->on('users')->onDelete('set null');
            
            // Add checkout_notes field
            $table->text('checkout_notes')->nullable()->after('notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guest_entries', function (Blueprint $table) {
            // Drop foreign key and column
            $table->dropForeign(['checked_out_by']);
            $table->dropColumn(['checked_out_by', 'checkout_notes']);
        });
    }
};
