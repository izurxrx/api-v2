<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guest_entries', function (Blueprint $table) {
            $table->dropColumn([
                'payment_status',
                'amount_paid',
                'balance',
                'payment_method',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('guest_entries', function (Blueprint $table) {
            $table->enum('payment_status', ['Paid', 'Unpaid'])->default('Unpaid');
            $table->decimal('amount_paid', 10, 2)->nullable();
            $table->decimal('balance', 10, 2)->nullable();
            $table->string('payment_method')->nullable();
        });
    }
};