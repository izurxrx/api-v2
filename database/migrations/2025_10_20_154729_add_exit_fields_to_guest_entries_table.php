<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guest_entries', function (Blueprint $table) {
            $table->date('exit_date')->nullable()->after('checkout_datetime');
            $table->time('exit_time')->nullable()->after('exit_date');
            
            $table->index('exit_date');
        });
    }

    public function down(): void
    {
        Schema::table('guest_entries', function (Blueprint $table) {
            $table->dropColumn(['exit_date', 'exit_time']);
        });
    }
};