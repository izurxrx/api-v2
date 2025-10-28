<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds discount_mode column to guest_entry_details table
     * This allows individual guest groups to have Direct discounts applied
     */
    public function up(): void
    {
        // Check if column already exists
        if (!Schema::hasColumn('guest_entry_details', 'discount_mode')) {
            // Use raw SQL to add ENUM column with proper placement
            DB::statement("
                ALTER TABLE guest_entry_details 
                ADD COLUMN discount_mode 
                ENUM('None','Direct') NOT NULL DEFAULT 'None'
                COMMENT 'Detail-level discount mode. None: no discount for this group. Direct: specific direct discount from discounts table.'
                AFTER rate_id
            ");
            
            // Add index for better query performance
            Schema::table('guest_entry_details', function (Blueprint $table) {
                $table->index('discount_mode', 'idx_discount_mode_details');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('guest_entry_details', 'discount_mode')) {
            Schema::table('guest_entry_details', function (Blueprint $table) {
                // Drop index first
                $table->dropIndex('idx_discount_mode_details');
                // Drop column
                $table->dropColumn('discount_mode');
            });
        }
    }
};
