<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->index(['booking_status', 'check_out_datetime']);
            $table->index('created_by');
        });

        Schema::table('guest_entries', function (Blueprint $table) {
            $table->index(['is_checked_out', 'checkout_datetime']);
            $table->index('created_by');
        });

        Schema::table('billings', function (Blueprint $table) {
            $table->index(['billable_type', 'billable_id']);
            $table->index(['payment_status', 'billing_status']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['billing_id', 'payment_date']);
            $table->index('payment_method');
        });
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
            $table->dropIndex(['billable_type', 'billable_id']);
            $table->dropIndex(['payment_status', 'billing_status']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['billing_id', 'payment_date']);
            $table->dropIndex(['payment_method']);
        });
    }
};