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
        Schema::table('billings', function (Blueprint $table) {
            // Add metadata JSON column for storing:
            // - per_guest_rates: {adult: 100, senior: 80, child: 50, infant: 0}
            // - entry_type: 'walk_in' or 'booking'
            $table->json('metadata')->nullable()->after('notes');
            
            // Add index for polymorphic relationship queries (improves extension lookups)
            if (!Schema::hasIndex('billings', 'billings_billable_type_billable_id_index')) {
                $table->index(['billable_type', 'billable_id']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            // Remove the index first
            $table->dropIndex('billings_billable_type_billable_id_index');
            
            // Remove metadata column
            $table->dropColumn('metadata');
        });
    }
};
