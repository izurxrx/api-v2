<?php

namespace App\Models;

use DB;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Log;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Billing extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    // ========================================
    // ✅ NEW: STATUS CONSTANTS
    // ========================================
    
    const STATUS_PENDING = 'pending';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_ACTIVE = 'active';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_VOIDED = 'voided';
    
    const PAYMENT_UNPAID = 'unpaid';
    const PAYMENT_PARTIAL = 'partial';
    const PAYMENT_PAID = 'paid';
    const PAYMENT_REFUNDED = 'refunded';
    const PAYMENT_CANCELLED = 'cancelled';

    /**
     * ✅ NEW: Valid status transitions map
     */
    private const STATUS_TRANSITIONS = [
        self::STATUS_PENDING => [
            self::STATUS_CONFIRMED,
            self::STATUS_ACTIVE,
            self::STATUS_CANCELLED,
            self::STATUS_VOIDED,
        ],
        self::STATUS_CONFIRMED => [
            self::STATUS_ACTIVE,
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
            self::STATUS_VOIDED,
        ],
        self::STATUS_ACTIVE => [
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
            self::STATUS_VOIDED,
        ],
        self::STATUS_COMPLETED => [
            self::STATUS_VOIDED, // Only admin can void completed
        ],
        self::STATUS_CANCELLED => [], // Cannot transition from cancelled
        self::STATUS_VOIDED => [], // Cannot transition from voided
    ];

    protected $fillable = [
        'billable_type',
        'billable_id',
        'billing_number',
        'subtotal',
        'discount_amount',
        'total_amount',
        'downpayment_amount',
        'downpayment_paid',
        'is_downpayment_paid',
        'payment_type',
        'amount_paid',
        'balance',
        'refund_amount',
        'refund_reason',
        'refunded_by',
        'refunded_at',
        'payment_status',
        'billing_status',
        'billed_at',
        'due_date',
        'paid_at',
        'voided_at',
        'cancellation_reason',
        'cancelled_by',
        'created_by',
        'notes',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'downpayment_amount' => 'decimal:2',
        'downpayment_paid' => 'decimal:2',
        'is_downpayment_paid' => 'boolean',
        'amount_paid' => 'decimal:2',
        'balance' => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'billed_at' => 'datetime',
        'due_date' => 'datetime',
        'paid_at' => 'datetime',
        'voided_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    // ========================================
    // ✅ NEW: STATUS VALIDATION METHODS
    // ========================================

    /**
     * Check if status transition is valid
     */
    public function canTransitionTo(string $newStatus): bool
    {
        $currentStatus = $this->billing_status;
        
        if (!isset(self::STATUS_TRANSITIONS[$currentStatus])) {
            return false;
        }
        
        return in_array($newStatus, self::STATUS_TRANSITIONS[$currentStatus]);
    }

    /**
     * Update billing status with validation
     * 
     * @throws \Exception if transition is invalid
     */
    public function updateStatus(string $newStatus): void
    {
        if (!$this->canTransitionTo($newStatus)) {
            throw new \Exception(
                sprintf(
                    'Invalid status transition: Cannot change from "%s" to "%s"',
                    $this->billing_status,
                    $newStatus
                )
            );
        }
        
        $oldStatus = $this->billing_status;
        $this->billing_status = $newStatus;
        $this->save();
        
        Log::info('Billing status changed', [
            'billing_id' => $this->id,
            'billing_number' => $this->billing_number,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'changed_by' => auth()->id(),
        ]);
    }

    /**
     * Get all valid statuses for current state
     */
    public function getAvailableStatuses(): array
    {
        return self::STATUS_TRANSITIONS[$this->billing_status] ?? [];
    }

    /**
     * Check if billing is in a final state (no more transitions possible)
     */
    public function isFinalStatus(): bool
    {
        return in_array($this->billing_status, [
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
            self::STATUS_VOIDED,
        ]);
    }

    /**
     * Check if billing can accept payments
     */
    public function canAcceptPayments(): bool
    {
        return !in_array($this->billing_status, [
            self::STATUS_CANCELLED,
            self::STATUS_VOIDED,
        ]) && $this->payment_status !== self::PAYMENT_PAID;
    }

    // ========================================
    // EXISTING: BUSINESS LOGIC METHODS
    // ========================================

    /**
     * Check if booking can be cancelled for free
     */
    public function canCancelForFree(): bool
    {
        return !$this->is_downpayment_paid;
    }

    /**
     * Calculate required downpayment (e.g., 50% of total)
     */
    public function calculateDownpayment(float $percentage = 50): float
    {
        return ($this->total_amount * $percentage) / 100;
    }

    /**
     * Check if downpayment requirement is met
     */
    public function hasMetDownpaymentRequirement(): bool
    {
        return $this->downpayment_paid >= $this->downpayment_amount;
    }

    // ========================================
    // RELATIONSHIPS
    // ========================================

    /**
     * Get the billable model (Booking or GuestEntry)
     */
    public function billable()
    {
        return $this->morphTo();
    }

    /**
     * Get all payments for this billing
     */
    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Get all extensions for this billing
     */
    public function extensions()
    {
        return $this->hasMany(BillingExtension::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function refundedBy()
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }

    // ========================================
    // ACTIVITY LOG
    // ========================================

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['billing_number', 'total_amount', 'amount_paid', 'balance', 'payment_status', 'billing_status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // ========================================
    // HELPER METHODS
    // ========================================

    /**
     * Update totals when booking/guest entry changes
     */
    public function updateTotals(float $subtotal, float $discountAmount, float $totalAmount): void
    {
        $this->subtotal = $subtotal;
        $this->discount_amount = $discountAmount;
        $this->total_amount = $totalAmount;
        $this->balance = $totalAmount - $this->amount_paid;
        
        $this->updatePaymentStatus();
        $this->save();
    }

    /**
     * Record a payment for this billing
     * 
     * ✅ FIXED: Removed nested DB::transaction
     */
    public function recordPayment(
        float $amount,
        string $paymentMethod,
        string $paymentType = 'partial',
        float $changeAmount = 0,
        ?string $referenceNumber = null,
        ?string $notes = null,
        ?int $receivedBy = null
    ): Payment {
        // ❌ REMOVED: DB::transaction (Controller/Service handles it now)
        
        // Validate payment amount
        if ($amount <= 0) {
            throw new \Exception('Payment amount must be greater than zero');
        }

        if ($amount > $this->balance) {
            throw new \Exception(
                sprintf('Payment amount (₱%.2f) exceeds balance (₱%.2f)', 
                $amount, 
                $this->balance)
            );
        }
        
        // Validate billing can accept payments
        if (!$this->canAcceptPayments()) {
            throw new \Exception(
                sprintf('Cannot accept payment for billing with status: %s', $this->billing_status)
            );
        }

        // Create payment record
        $payment = Payment::create([
            'billing_id' => $this->id,
            'payment_number' => Payment::generatePaymentNumber(),
            'amount' => $amount,
            'change_amount' => $changeAmount,
            'payment_method' => $paymentMethod,
            'payment_type' => $paymentType,
            'reference_number' => $referenceNumber,
            'notes' => $notes,
            'payment_date' => now(),
            'received_by' => $receivedBy ?? auth()->id(),
        ]);

        // Update billing amounts
        $newAmountPaid = $this->amount_paid + $amount;
        $newBalance = $this->total_amount - $newAmountPaid;

        // Determine payment status
        $paymentStatus = self::PAYMENT_PARTIAL;
        if ($newBalance <= 0) {
            $paymentStatus = self::PAYMENT_PAID;
        } elseif ($newAmountPaid <= 0) {
            $paymentStatus = self::PAYMENT_UNPAID;
        }

        // Calculate downpayment_paid - track how much has been paid towards downpayment
        $currentDownpaymentPaid = $this->downpayment_paid ?? 0; // Handle NULL
        $newDownpaymentPaid = $currentDownpaymentPaid + $amount;
        // Cap at downpayment_amount (excess goes to balance)
        if ($newDownpaymentPaid > $this->downpayment_amount) {
            $newDownpaymentPaid = $this->downpayment_amount;
        }

        // Check if downpayment requirement is now met
        $isDownpaymentPaid = $newDownpaymentPaid >= $this->downpayment_amount;

        // Update billing
        // ✅ NOTE: billing_status is NOT changed to 'completed' here
        // It only becomes 'completed' when guest checks out (via checkout endpoint)
        $this->update([
            'amount_paid' => $newAmountPaid,
            'balance' => max(0, $newBalance),
            'payment_status' => $paymentStatus,
            'downpayment_paid' => $newDownpaymentPaid,
            'is_downpayment_paid' => $isDownpaymentPaid,
            'paid_at' => $paymentStatus === self::PAYMENT_PAID ? now() : $this->paid_at,
        ]);

        // Update related booking/guest entry status based on payment
        $this->updateBillableStatus();

        Log::info('Payment recorded', [
            'payment_id' => $payment->id,
            'billing_id' => $this->id,
            'amount' => $amount,
            'payment_type' => $paymentType,
            'new_balance' => $newBalance,
        ]);

        return $payment;
    }

    /**
     * Update payment status based on amounts
     */
    public function updatePaymentStatus(): void
    {
        if ($this->balance <= 0) {
            $this->payment_status = self::PAYMENT_PAID;
            $this->paid_at = $this->paid_at ?? now();
            $this->billing_status = self::STATUS_COMPLETED;
        } elseif ($this->amount_paid > 0) {
            $this->payment_status = self::PAYMENT_PARTIAL;
            
            // Update billing status based on downpayment
            if ($this->is_downpayment_paid) {
                $this->billing_status = self::STATUS_CONFIRMED;
            } else {
                $this->billing_status = self::STATUS_ACTIVE;
            }
        } else {
            $this->payment_status = self::PAYMENT_UNPAID;
            $this->billing_status = self::STATUS_PENDING;
        }
    }

    /**
     * Update the booking or guest entry status based on payment progress
     */
    public function updateBillableStatus(): void
    {
        $billable = $this->billable;
        
        if (!$billable) {
            return;
        }

        // For Bookings
        if ($billable instanceof Booking) {
            $currentStatus = $billable->booking_status;
            
            // Only update if booking is currently in Pending or Confirmed status
            if (in_array($currentStatus, ['Pending', 'Confirmed'])) {
                // If fully paid, set to Confirmed
                if ($this->payment_status === self::PAYMENT_PAID || $this->balance <= 0) {
                    $billable->booking_status = 'Confirmed';
                    $billable->save();
                    
                    Log::info('Booking status updated to Confirmed (fully paid)', [
                        'booking_id' => $billable->id,
                        'billing_id' => $this->id,
                        'amount_paid' => $this->amount_paid,
                    ]);
                }
                // If partially paid and meets downpayment requirement, set to Confirmed
                elseif ($this->hasMetDownpaymentRequirement() && $currentStatus === 'Pending') {
                    $billable->booking_status = 'Confirmed';
                    $billable->save();
                    
                    Log::info('Booking status updated to Confirmed (downpayment met)', [
                        'booking_id' => $billable->id,
                        'billing_id' => $this->id,
                        'downpayment_paid' => $this->downpayment_paid,
                        'downpayment_required' => $this->downpayment_amount,
                    ]);
                }
            }
        }
        
        // For Guest Entries
        if ($billable instanceof GuestEntry) {
            // ✅ Walk-in transactions complete immediately upon full payment
            if ($billable->entry_type === 'walk_in' && $this->balance <= 0) {
                // Close the billing transaction for walk-ins
                $this->update([
                    'billing_status' => self::STATUS_COMPLETED,
                    'payment_status' => self::PAYMENT_PAID,
                    'paid_at' => $this->paid_at ?? now(),
                ]);
                
                Log::info('Walk-in transaction completed upon payment', [
                    'guest_entry_id' => $billable->id,
                    'billing_id' => $this->id,
                    'entry_reference' => $billable->entry_reference,
                    'amount_paid' => $this->amount_paid,
                ]);
            }
            
            // ✅ Swimming booking entries complete immediately upon full payment (day use, no checkout)
            if ($billable->booking_id && $this->balance <= 0) {
                $booking = $billable->booking;
                
                if ($booking && $booking->booking_type === 'Swimming') {
                    // Close the billing transaction for Swimming bookings
                    $this->update([
                        'billing_status' => self::STATUS_COMPLETED,
                        'payment_status' => self::PAYMENT_PAID,
                        'paid_at' => $this->paid_at ?? now(),
                    ]);
                    
                    Log::info('Swimming booking transaction completed upon payment', [
                        'guest_entry_id' => $billable->id,
                        'booking_id' => $booking->id,
                        'billing_id' => $this->id,
                        'booking_reference' => $booking->booking_reference,
                        'amount_paid' => $this->amount_paid,
                    ]);
                }
            }
            
            // Package booking-based guest entries still require checkout
        }
    }

    /**
     * Process refund
     */
    public function processRefund(float $amount, string $reason, $cancelledBy = null): void
    {
        $this->refund_amount += $amount;
        $this->amount_paid -= $amount;
        $this->balance = $this->total_amount - $this->amount_paid;
        $this->payment_status = self::PAYMENT_REFUNDED;
        $this->billing_status = self::STATUS_VOIDED;
        $this->cancellation_reason = $reason;
        $this->cancelled_by = $cancelledBy ?? auth()->id();
        $this->voided_at = now();
        
        $this->save();
    }

    /**
     * Generate unique billing number
     */
    public static function generateBillingNumber(): string
    {
        $year = date('Y');
        $lastBilling = self::whereYear('created_at', $year)
            ->orderBy('id', 'desc')
            ->first();
        
        $number = $lastBilling ? ((int) substr($lastBilling->billing_number, -5)) + 1 : 1;
        
        return 'BILL-' . $year . '-' . str_pad($number, 5, '0', STR_PAD_LEFT);
    }

    // ========================================
    // SCOPES
    // ========================================

    public function scopeUnpaid($query)
    {
        return $query->where('payment_status', self::PAYMENT_UNPAID);
    }

    public function scopePartial($query)
    {
        return $query->where('payment_status', self::PAYMENT_PARTIAL);
    }

    public function scopePaid($query)
    {
        return $query->where('payment_status', self::PAYMENT_PAID);
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('billing_status', [
            self::STATUS_CANCELLED,
            self::STATUS_VOIDED,
            self::STATUS_COMPLETED,
        ]);
    }

    public function scopeOverdue($query)
    {
        return $query->where('payment_status', '!=', self::PAYMENT_PAID)
                     ->where('due_date', '<', now())
                     ->whereNotNull('due_date');
    }
}