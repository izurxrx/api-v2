<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // First, drop the old payments table structure
        Schema::dropIfExists('payments');
        
        // Then create the new structure
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            
            // Link to billing record
            $table->foreignId('billing_id')->constrained('billings')->onDelete('cascade');
            
            // Payment Details
            $table->string('payment_number')->unique(); // e.g., PAY-2025-00001
            $table->decimal('amount', 10, 2); // Amount of THIS payment
            $table->decimal('change_amount', 10, 2)->default(0); // Cash change given
            
            // Payment Method
            $table->enum('payment_method', [
                'cash',
                'gcash',
                'bank_transfer',
                'credit_card',
                'debit_card',
                'other'
            ]);
            
            // Payment Type
            $table->enum('payment_type', [
                'deposit',      // Initial deposit (bookings)
                'partial',      // Partial payment (bookings)
                'full',         // Full payment (guest entries or final booking payment)
                'balance',      // Remaining balance (bookings)
                'refund'        // Refund payment
            ])->default('full');
            
            // Reference Information
            $table->string('reference_number')->nullable(); // GCash ref, bank ref, etc.
            $table->text('notes')->nullable(); // Payment notes
            
            // Who received the payment
            $table->foreignId('received_by')->constrained('users')->onDelete('cascade');
            $table->timestamp('payment_date')->useCurrent();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('payment_number');
            $table->index('payment_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        
        // Recreate old structure (based on your SQL file)
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->morphs('payable');
            $table->decimal('amount', 10, 2);
            $table->string('payment_method');
            $table->foreignId('received_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
        });
    }
};