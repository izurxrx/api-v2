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
            if (!Schema::hasColumn('billings', 'refund_reason')) {
                $table->string('refund_reason')->nullable()->after('refund_amount');
            }
            if (!Schema::hasColumn('billings', 'refunded_by')) {
                $table->unsignedBigInteger('refunded_by')->nullable()->after('refund_reason');
            }
            if (!Schema::hasColumn('billings', 'refunded_at')) {
                $table->timestamp('refunded_at')->nullable()->after('refunded_by');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            $table->dropColumn(['refund_reason', 'refunded_by', 'refunded_at']);
        });
    }
};
