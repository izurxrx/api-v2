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
            // Add entrance_rate_id for walk-in guests
            $table->foreignId('entrance_rate_id')->nullable()->after('entry_type')
                ->constrained('rates')->onDelete('set null')
                ->comment('FK to rates table for entrance fee');
            
            // Add index for better query performance
            $table->index('entrance_rate_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guest_entries', function (Blueprint $table) {
            $table->dropForeign(['entrance_rate_id']);
            $table->dropIndex(['entrance_rate_id']);
            $table->dropColumn('entrance_rate_id');
        });
    }
};
