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
        // Add discount_mode to guest_entries (only if it doesn't exist)
        if (!Schema::hasColumn('guest_entries', 'discount_mode')) {
            Schema::table('guest_entries', function (Blueprint $table) {
                $table->enum('discount_mode', ['None', 'Seasonal', 'Direct', 'Manual'])
                    ->default('None')
                    ->after('entry_time')
                    ->comment('Discount mode applied to entire entry. Direct/Seasonal: select from discounts table per group. Manual: enter amount per group.');
            });
        }

        // Migrate existing data (if any exists)
        // Take the discount_mode from the first detail row
        DB::statement("
            UPDATE guest_entries ge
            LEFT JOIN (
                SELECT guest_entry_id, discount_mode,
                       ROW_NUMBER() OVER (PARTITION BY guest_entry_id ORDER BY id) as rn
                FROM guest_entry_details
            ) ged ON ge.id = ged.guest_entry_id AND ged.rn = 1
            SET ge.discount_mode = COALESCE(ged.discount_mode, 'None')
        ");

        // Remove discount_mode from guest_entry_details
        Schema::table('guest_entry_details', function (Blueprint $table) {
            $table->dropColumn('discount_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Add discount_mode back to guest_entry_details
        Schema::table('guest_entry_details', function (Blueprint $table) {
            $table->enum('discount_mode', ['None', 'Seasonal', 'Direct', 'Manual'])
                ->default('None')
                ->after('guest_type_name');
        });

        // Migrate data back
        DB::statement("
            UPDATE guest_entry_details ged
            JOIN guest_entries ge ON ged.guest_entry_id = ge.id
            SET ged.discount_mode = ge.discount_mode
        ");

        // Remove from guest_entries
        Schema::table('guest_entries', function (Blueprint $table) {
            $table->dropColumn('discount_mode');
        });
    }
};