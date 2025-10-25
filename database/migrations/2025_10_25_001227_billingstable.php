<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billings', function (Blueprint $table) {
            $table->id();
            
            // Reference to what's being billed (polymorphic)
            // This automatically creates an index, so we don't need to add it again
            $table->morphs('billable'); // Creates billable_type, billable_id, and INDEX
            
            // Billing Information
            $table->string('billing_number')->unique();
            $table->decimal('subtotal', 10, 2);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2);
            $table->decimal('amount_paid', 10, 2)->default(0);
            $table->decimal('balance', 10, 2);
            
            // Payment Status
            $table->enum('payment_status', [
                'unpaid',
                'partial',
                'paid',
                'refunded',
                'cancelled'
            ])->default('unpaid');
            
            // Billing Status
            $table->enum('billing_status', [
                'pending',
                'active',
                'completed',
                'voided'
            ])->default('pending');
            
            // Important Dates
            $table->timestamp('billed_at')->useCurrent();
            $table->timestamp('due_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            
            // Cancellation/Refund Info
            $table->decimal('refund_amount', 10, 2)->default(0);
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->onDelete('set null');
            
            // Audit Trail
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->text('notes')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            // Additional indexes (morphs() already created the billable index)
            $table->index('billing_number');
            $table->index('payment_status');
            $table->index('billed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billings');
    }
};