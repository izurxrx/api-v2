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
        // Update the discount_mode ENUM to include combination values
        DB::statement("
            ALTER TABLE bookings 
            MODIFY COLUMN discount_mode 
            ENUM('None', 'Direct', 'Seasonal', 'Manual', 'Direct+Manual', 'Seasonal+Manual') 
            DEFAULT 'None'
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to original ENUM values
        // First, update any combination values back to 'Manual' to avoid data loss
        DB::statement("
            UPDATE bookings 
            SET discount_mode = 'Manual' 
            WHERE discount_mode IN ('Direct+Manual', 'Seasonal+Manual')
        ");
        
        DB::statement("
            ALTER TABLE bookings 
            MODIFY COLUMN discount_mode 
            ENUM('None', 'Direct', 'Seasonal', 'Manual') 
            DEFAULT 'None'
        ");
    }
};
