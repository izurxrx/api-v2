<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Only add indexes that don't exist
        $this->addIndexIfNotExists('bookings', ['booking_status', 'check_out_datetime']);
        $this->addIndexIfNotExists('bookings', ['created_by']);
        
        $this->addIndexIfNotExists('guest_entries', ['is_checked_out', 'checkout_datetime']);
        $this->addIndexIfNotExists('guest_entries', ['created_by']);
        
        // Skip billings indexes since they already exist
        $this->addIndexIfNotExists('billings', ['payment_status', 'billing_status']);
        
        $this->addIndexIfNotExists('payments', ['billing_id', 'payment_date']);
        $this->addIndexIfNotExists('payments', ['payment_method']);
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['booking_status', 'check_out_datetime']);
            $table->dropIndex(['created_by']);
        });

        Schema::table('guest_entries', function (Blueprint $table) {
            $table->dropIndex(['is_checked_out', 'checkout_datetime']);
            $table->dropIndex(['created_by']);
        });

        Schema::table('billings', function (Blueprint $table) {
            $table->dropIndex(['payment_status', 'billing_status']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['billing_id', 'payment_date']);
            $table->dropIndex(['payment_method']);
        });
    }

    private function addIndexIfNotExists(string $table, array $columns): void
    {
        $indexName = $table . '_' . implode('_', $columns) . '_index';
        
        $database = Schema::getConnection()->getDatabaseName();
        
        $exists = DB::select(
            "SELECT COUNT(*) as count 
             FROM INFORMATION_SCHEMA.STATISTICS 
             WHERE table_schema = ? 
             AND table_name = ? 
             AND index_name = ?",
            [$database, $table, $indexName]
        );
        
        if ($exists[0]->count == 0) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                $blueprint->index($columns);
            });
            echo "✓ Added index on $table: " . implode(', ', $columns) . "\n";
        } else {
            echo "⊙ Index already exists on $table: " . implode(', ', $columns) . "\n";
        }
    }
};