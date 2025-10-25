<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            $table->decimal('downpayment_amount', 10, 2)->default(0)->after('total_amount')
                ->comment('Required downpayment amount');
            
            $table->decimal('downpayment_paid', 10, 2)->default(0)->after('downpayment_amount')
                ->comment('Amount paid as downpayment');
            
            $table->boolean('is_downpayment_paid')->default(false)->after('downpayment_paid')
                ->comment('Whether downpayment requirement is met');
            
            $table->enum('payment_type', ['full', 'downpayment', 'balance'])->nullable()->after('payment_status')
                ->comment('Type of payment made');
        });
    }

    public function down(): void
    {
        Schema::table('billings', function (Blueprint $table) {
            $table->dropColumn([
                'downpayment_amount',
                'downpayment_paid',
                'is_downpayment_paid',
                'payment_type'
            ]);
        });
    }
};