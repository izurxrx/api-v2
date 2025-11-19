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
     * @return void
     */
    public function up()
    {
        // Step 1: Drop CHECK constraints that might reference manual_discount_amount
        // We need to drop and recreate the constraint that validates amounts
        
        // Drop the amounts constraint (likely references manual_discount_amount)
        DB::statement("ALTER TABLE guest_entry_details DROP CONSTRAINT IF EXISTS chk_amounts_non_negative");
        
        // Step 2: Drop the manual_discount_amount column (only if exists)
        Schema::table('guest_entry_details', function (Blueprint $table) {
            if (Schema::hasColumn('guest_entry_details', 'manual_discount_amount')) {
                $table->dropColumn('manual_discount_amount');
            }
        });

        // Step 3: Recreate the amounts CHECK constraint without manual_discount_amount
        DB::statement("
            ALTER TABLE guest_entry_details 
            ADD CONSTRAINT chk_amounts_non_negative
            CHECK (
                base_rate >= 0 
                AND discount_amount >= 0 
                AND final_rate >= 0 
                AND total_amount >= 0
            )
        ");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Drop the updated constraint
        DB::statement("ALTER TABLE guest_entry_details DROP CONSTRAINT IF EXISTS chk_amounts_non_negative");

        // Add back the manual_discount_amount column
        Schema::table('guest_entry_details', function (Blueprint $table) {
            $table->decimal('manual_discount_amount', 10, 2)
                  ->default(0)
                  ->after('discount_amount')
                  ->comment('Manual discount input (only when discount_mode=Manual)');
        });

        // Recreate the original constraint with manual_discount_amount
        DB::statement("
            ALTER TABLE guest_entry_details 
            ADD CONSTRAINT chk_amounts_non_negativ e
            CHECK (
                base_rate >= 0 
                AND discount_amount >= 0 
                AND manual_discount_amount >= 0 
                AND final_rate >= 0 
                AND total_amount >= 0
            )
        ");
    }
};