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
        // Drop the old constraint that doesn't allow combination discount modes
        DB::statement("ALTER TABLE bookings DROP CONSTRAINT IF EXISTS chk_discount_stacking");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Recreate the constraint with updated values including combinations
        DB::statement("
            ALTER TABLE bookings 
            ADD CONSTRAINT chk_discount_stacking 
            CHECK (
                discount_mode IN ('None', 'Direct', 'Seasonal', 'Manual', 'Direct+Manual', 'Seasonal+Manual')
            )
        ");
    }
};
