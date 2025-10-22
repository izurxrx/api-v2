<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('guest_entries', function (Blueprint $table) {
            // Add check-in datetime - set when entry is saved/paid
            $table->datetime('check_in_datetime')
                ->nullable()
                ->after('entry_time')
                ->comment('Set when guest entry is checked in (saved with payment). Fields locked after this.');
            
            // Add checkout datetime - set when facilities are returned
            $table->datetime('checkout_datetime')
                ->nullable()
                ->after('is_checked_out')
                ->comment('Set when guest checks out and returns facilities.');
        });

        // Migrate existing data: If entry exists, assume it was checked in
        DB::statement("
            UPDATE guest_entries
            SET check_in_datetime = created_at
            WHERE check_in_datetime IS NULL
        ");

        // If is_checked_out = 1, set checkout_datetime
        DB::statement("
            UPDATE guest_entries
            SET checkout_datetime = updated_at
            WHERE is_checked_out = 1 AND checkout_datetime IS NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guest_entries', function (Blueprint $table) {
            $table->dropColumn(['check_in_datetime', 'checkout_datetime']);
        });
    }
};