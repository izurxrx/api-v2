<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        DB::statement("
            ALTER TABLE guest_entries 
            MODIFY COLUMN discount_mode ENUM('None', 'Seasonal', 'Manual', 'Direct') 
            DEFAULT 'None'
        ");
    }

    public function down()
    {
        DB::statement("
            ALTER TABLE guest_entries 
            MODIFY COLUMN discount_mode ENUM('None', 'Seasonal', 'Manual') 
            DEFAULT 'None'
        ");
    }
};