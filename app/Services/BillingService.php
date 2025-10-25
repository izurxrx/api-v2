<?php

namespace App\Services;

use App\Models\Billing;
use App\Models\Payment;
use App\Models\Booking;
use App\Models\GuestEntry;
use Illuminate\Support\Facades\DB;
use Log;

class BillingService
{
    /**
     * Create billing for a new booking
     * 
     * ✅ FIXED: Default downpayment changed from 30% to 50%
     * ✅ FIXED: Removed nested DB::transaction (caller handles transaction)
     */
    public function createBillingForBooking(
        Booking $booking, 
        array $paymentData = null,
        float $downpaymentPercentage = 50 // ✅ CHANGED: 30 → 50
    ): Billing {
        // ❌ REMOVED: DB::transaction (Controller handles it now)
        
        $totalAmount = $booking->total_amount;
        $downpaymentRequired = ($totalAmount * $downpaymentPercentage) / 100;
        
        // Create billing
        $billing = Billing::create([
            'billable_type' => Booking::class,
            'billable_id' => $booking->id,
            'billing_number' => Billing::generateBillingNumber(),
            'subtotal' => $booking->subtotal,
            'discount_amount' => $booking->discount_amount ?? 0,
            'total_amount' => $totalAmount,
            'downpayment_amount' => $downpaymentRequired,
            'downpayment_paid' => 0,
            'is_downpayment_paid' => false,
            'amount_paid' => 0,
            'balance' => $totalAmount,
            'payment_status' => 'unpaid',
            'billing_status' => 'pending',
            'billed_at' => now(),
            'due_date' => $booking->check_in_datetime,
            'created_by' => auth()->id(),
            'notes' => sprintf(
                'Downpayment required: ₱%.2f (%d%%) to confirm booking',
                $downpaymentRequired,
                $downpaymentPercentage
            ),
        ]);

        // If payment provided, record it
        if ($paymentData && isset($paymentData['amount_paid'])) {
            $amountPaid = $paymentData['amount_paid'];
            
            // Determine if this is downpayment or full payment
            if ($amountPaid >= $totalAmount) {
                // Full payment
                $this->recordPayment($billing, $paymentData, 'full');
                
                // Update booking to Confirmed
                $booking->update(['booking_status' => 'Confirmed']);
                
            } elseif ($amountPaid >= $downpaymentRequired) {
                // Downpayment
                $this->recordPayment($billing, $paymentData, 'downpayment');
                
                // Update booking to Confirmed
                $booking->update(['booking_status' => 'Confirmed']);
                
            } else {
                throw new \Exception(
                    sprintf(
                        'Payment amount (₱%.2f) is less than required downpayment (₱%.2f)',
                        $amountPaid,
                        $downpaymentRequired
                    )
                );
            }
        }

        return $billing->fresh();
    }

    /**
     * Create a billing record for a guest entry (requires full payment)
     * 
     * ✅ FIXED: Removed nested DB::transaction
     */
    public function createBillingForGuestEntry(GuestEntry $guestEntry, array $paymentData): Billing
    {
        // ❌ REMOVED: DB::transaction (Controller handles it now)
        
        $totalAmount = $guestEntry->total_amount;
        $amountPaid = $paymentData['amount_paid'] ?? 0;
        
        // Guest entries require FULL payment
        if ($amountPaid < $totalAmount) {
            throw new \Exception(
                sprintf('Full payment of ₱%.2f is required for walk-in guests. Only ₱%.2f provided.', 
                $totalAmount, 
                $amountPaid)
            );
        }
        
        // Create billing
        $billing = Billing::create([
            'billable_type' => GuestEntry::class,
            'billable_id' => $guestEntry->id,
            'billing_number' => Billing::generateBillingNumber(),
            'subtotal' => $guestEntry->subtotal,
            'discount_amount' => $guestEntry->discount_amount ?? 0,
            'total_amount' => $totalAmount,
            'amount_paid' => 0,
            'balance' => $totalAmount,
            'payment_status' => 'unpaid',
            'billing_status' => 'pending',
            'billed_at' => now(),
            'paid_at' => now(), // Immediate payment
            'created_by' => auth()->id(),
        ]);

        // Record full payment (no transaction here, model handles it)
        $billing->recordPayment(
            amount: $amountPaid,
            paymentMethod: $paymentData['payment_method'] ?? 'cash',
            paymentType: 'full',
            changeAmount: $paymentData['change_amount'] ?? 0,
            referenceNumber: $paymentData['reference_number'] ?? null,
            notes: $paymentData['notes'] ?? null,
            receivedBy: auth()->id()
        );

        return $billing->fresh();
    }

    /**
     * Record an additional payment for existing billing
     * 
     * ✅ FIXED: Removed nested DB::transaction
     * 
     * @param Billing $billing
     * @param array $paymentData
     * @param string|null $paymentType Optional: 'full', 'downpayment', 'partial', 'balance'
     * @return Payment
     */
    public function recordPayment(Billing $billing, array $paymentData, ?string $paymentType = null): Payment
    {
        // ❌ REMOVED: DB::transaction (Controller handles it now)
        
        $amountPaid = $paymentData['amount_paid'];
        
        // Validate amount doesn't exceed balance
        if ($amountPaid > $billing->balance) {
            throw new \Exception(
                sprintf('Payment amount (₱%.2f) exceeds remaining balance (₱%.2f)', 
                $amountPaid, 
                $billing->balance)
            );
        }
        
        // Determine payment type if not explicitly provided
        if ($paymentType === null) {
            if ($amountPaid >= $billing->balance) {
                $paymentType = 'balance'; // Final payment
            } else {
                $paymentType = 'partial'; // Partial payment
            }
        }
        
        // If this is a downpayment, validate it meets the requirement
        if ($paymentType === 'downpayment') {
            if ($billing->is_downpayment_paid) {
                throw new \Exception('Downpayment has already been paid for this billing');
            }
            
            if ($amountPaid < $billing->downpayment_amount) {
                throw new \Exception(
                    sprintf('Downpayment amount (₱%.2f) is less than required (₱%.2f)', 
                    $amountPaid, 
                    $billing->downpayment_amount)
                );
            }
        }
        
        // Record the payment (model method has no transaction)
        $payment = $billing->recordPayment(
            amount: $amountPaid,
            paymentMethod: $paymentData['payment_method'],
            paymentType: $paymentType,
            changeAmount: $paymentData['change_amount'] ?? 0,
            referenceNumber: $paymentData['reference_number'] ?? null,
            notes: $paymentData['notes'] ?? null,
            receivedBy: auth()->id()
        );
        
        // Update downpayment status if this is a downpayment
        if ($paymentType === 'downpayment') {
            $billing->update([
                'downpayment_paid' => $billing->downpayment_paid + $amountPaid,
                'is_downpayment_paid' => true,
            ]);
            
            Log::info('Downpayment recorded', [
                'billing_id' => $billing->id,
                'downpayment_amount' => $amountPaid,
                'billing_type' => class_basename($billing->billable_type),
            ]);
        }
        
        return $payment->fresh();
    }

    /**
     * ✅ NEW: Reverse a payment (creates negative payment entry)
     * Replaces the deletePayment method for better accounting
     * 
     * @param Payment $payment
     * @param string $reason
     * @return Payment The reversal payment record
     */
    public function reversePayment(Payment $payment, string $reason): Payment
    {
        // ❌ NO transaction here - Controller handles it
        
        $billing = $payment->billing;
        
        // Validate: Can only reverse recent payments (within 24 hours)
        $hoursSinceCreation = $payment->created_at->diffInHours(now());
        if ($hoursSinceCreation > config('billing.payment.reversal_window_hours', 24)) {
            throw new \Exception(
                sprintf(
                    'Payment can only be reversed within %d hours of creation. This payment was created %d hours ago.',
                    config('billing.payment.reversal_window_hours', 24),
                    $hoursSinceCreation
                )
            );
        }
        
        // Validate: Cannot reverse if already checked out
        $billable = $billing->billable;
        if ($billable instanceof Booking && $billable->booking_status === 'Checked_Out') {
            throw new \Exception('Cannot reverse payment after checkout');
        }
        
        if ($billable instanceof GuestEntry && $billable->is_checked_out) {
            throw new \Exception('Cannot reverse payment after checkout');
        }
        
        // Create reversal payment (negative amount)
        $reversal = Payment::create([
            'billing_id' => $billing->id,
            'payment_number' => Payment::generatePaymentNumber(),
            'amount' => -$payment->amount, // Negative!
            'change_amount' => 0,
            'payment_method' => $payment->payment_method,
            'payment_type' => 'reversal',
            'reference_number' => 'REVERSAL-' . $payment->payment_number,
            'notes' => "Reversal of payment {$payment->payment_number}. Reason: {$reason}",
            'payment_date' => now(),
            'received_by' => auth()->id(),
        ]);
        
        // Update billing amounts
        $billing->amount_paid -= $payment->amount;
        $billing->balance = $billing->total_amount - $billing->amount_paid;
        
        // Update payment status
        $billing->updatePaymentStatus();
        $billing->save();
        
        // Mark original payment as reversed
        $payment->update([
            'notes' => ($payment->notes ?? '') . ' [REVERSED on ' . now()->format('Y-m-d H:i:s') . ']'
        ]);
        
        Log::warning('Payment reversed', [
            'payment_id' => $payment->id,
            'reversal_id' => $reversal->id,
            'amount' => $payment->amount,
            'reason' => $reason,
            'reversed_by' => auth()->id(),
            'billing_id' => $billing->id,
        ]);
        
        return $reversal;
    }

    /**
     * Cancel a billing and process refund if applicable
     * 
     * ✅ FIXED: Removed nested DB::transaction
     */
    public function cancelBilling(Billing $billing, string $reason): void
    {
        // ❌ REMOVED: DB::transaction (Controller handles it now)
        
        // Check if any payment was made
        if ($billing->amount_paid > 0) {
            // For bookings: No refund (deposit forfeited)
            // Just mark as cancelled
            $billing->payment_status = 'cancelled';
            $billing->billing_status = 'voided';
            $billing->cancellation_reason = $reason;
            $billing->cancelled_by = auth()->id();
            $billing->voided_at = now();
            $billing->balance = 0; // No longer owed
            $billing->save();
        } else {
            // No payment made, just cancel
            $billing->payment_status = 'cancelled';
            $billing->billing_status = 'voided';
            $billing->cancellation_reason = $reason;
            $billing->cancelled_by = auth()->id();
            $billing->voided_at = now();
            $billing->save();
        }
    }

    /**
     * ❌ DEPRECATED: Use reversePayment() instead
     * 
     * This method is kept for backward compatibility but will be removed in future versions.
     * Please use reversePayment() for better accounting practices.
     * 
     * @deprecated 1.0.0 Use reversePayment() instead
     */
    public function deletePayment(Payment $payment): void
    {
        throw new \Exception(
            'Payment deletion is no longer supported. Please use reversePayment() method instead for proper accounting.'
        );
    }
}