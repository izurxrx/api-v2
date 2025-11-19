<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Add composite index for report queries (only if not exists)
            if (!Schema::hasIndex('bookings', 'bookings_booking_status_check_out_datetime_index')) {
                $table->index(['booking_status', 'check_out_datetime']);
            }
            if (!Schema::hasIndex('bookings', 'bookings_created_by_index')) {
                $table->index('created_by');
            }
        });

        Schema::table('guest_entries', function (Blueprint $table) {
            // Add composite index for active guests queries (only if not exists)
            if (!Schema::hasIndex('guest_entries', 'guest_entries_is_checked_out_checkout_datetime_index')) {
                $table->index(['is_checked_out', 'checkout_datetime']);
            }
            if (!Schema::hasIndex('guest_entries', 'guest_entries_created_by_index')) {
                $table->index('created_by');
            }
        });

        Schema::table('billings', function (Blueprint $table) {
            // Add indexes for billing queries (only if not exists)
            if (!Schema::hasIndex('billings', 'billings_billable_type_billable_id_index')) {
                $table->index(['billable_type', 'billable_id']);
            }
            if (!Schema::hasIndex('billings', 'billings_payment_status_billing_status_index')) {
                $table->index(['payment_status', 'billing_status']);
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            // Add indexes for payment queries (only if not exists)
            if (!Schema::hasIndex('payments', 'payments_billing_id_payment_date_index')) {
                $table->index(['billing_id', 'payment_date']);
            }
            if (!Schema::hasIndex('payments', 'payments_payment_method_index')) {
                $table->index('payment_method');
            }
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