<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('guest_entry_facilities', function (Blueprint $table) {
            $table->integer('quantity')->default(1)->after('facility_id')->comment('Number of units of this facility');
        });
    }

    public function down()
    {
        Schema::table('guest_entry_facilities', function (Blueprint $table) {
            $table->dropColumn('quantity');
        });
    }
};