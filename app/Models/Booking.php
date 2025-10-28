<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Booking extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'booking_type',
        'entrance_rate_id',         // For Swimming bookings
        'discount_mode',            // None/Direct/Seasonal/Manual
        'discount_id',              // FK to discounts table
        'manual_discount_amount',   // Staff discount
        'discount_amount',          // Total discount applied
        'booking_reference',
        'guest_name',
        'contact_number',
        'facility_id', // Keep for backward compatibility
        'check_in_date', // Keep for backward compatibility
        'check_out_date', // Keep for backward compatibility
        'check_in_time', // Keep for backward compatibility
        'check_out_time', // Keep for backward compatibility
        'check_in_datetime',
        'check_out_datetime',
        'duration_hours',
        'actual_check_in_datetime',
        'checked_in_by',
        'actual_check_out_datetime',
        'checked_out_by',
        'number_of_guests',
        'guest_breakdown',
        'booking_status',
        'cancellation_deadline',
        'cancellation_reason',
        'facility_subtotal',
        'third_party_service_amount',
        'subtotal',
        'total_amount',
        // ❌ REMOVED: payment_status, amount_paid, balance
        'special_requests',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'check_in_date' => 'date',
        'check_out_date' => 'date',
        'check_in_datetime' => 'datetime',
        'check_out_datetime' => 'datetime',
        'cancellation_deadline' => 'datetime',
        'actual_check_in_datetime' => 'datetime',
        'actual_check_out_datetime' => 'datetime',
        'guest_breakdown' => 'array',
        'facility_subtotal' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'third_party_service_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'manual_discount_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',

    ];

    // Activity Log Configuration
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'booking_reference', 'guest_name', 'booking_status', 
                'total_amount' // Removed payment_status and balance
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // ========================================
    // RELATIONSHIPS
    // ========================================
    
    public function facility()
    {
        return $this->belongsTo(Facility::class);
    }

    /**
     * Get all facilities for this booking (Multi-facility support)
     */
    public function facilities()
    {
        return $this->hasMany(BookingFacility::class);
    }

    /**
     * Get all third-party services for this booking
     */
    public function thirdPartyServices()
    {
        return $this->hasMany(BookingThirdPartyService::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function checkedInBy()
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    public function checkedOutBy()
    {
        return $this->belongsTo(User::class, 'checked_out_by');
    }

    /**
     * ✅ NEW: Polymorphic relationship to billing
     */
    public function billing()
    {
        return $this->morphOne(Billing::class, 'billable');
    }

    /**
     * ✅ NEW: Relationship to guest entry (when booking is checked in)
     */
    public function guestEntry()
    {
        return $this->hasOne(GuestEntry::class, 'booking_id');
    }

    // ========================================
    // HELPER METHODS
    // ========================================
    
    /**
     * Check if booking can be cancelled
     */
    public function canBeCancelled(): bool
    {
        if (!in_array($this->booking_status, ['Pending', 'Confirmed'])) {
            return false;
        }
        
        // Can cancel if before cancellation deadline (72 hours before check-in)
        return now()->isBefore($this->cancellation_deadline);
    }

    /**
     * ✅ UPDATED: Check if booking requires payment (via billing)
     */
    public function requiresPayment(): bool
    {
        return $this->billing && $this->billing->balance > 0;
    }

    /**
     * ✅ UPDATED: Check if minimum deposit (50%) is paid
     */
    public function hasMinimumDeposit(): bool
    {
        if (!$this->billing) {
            return false;
        }
        
        $minimumDeposit = $this->billing->total_amount * 0.5;
        return $this->billing->amount_paid >= $minimumDeposit;
    }

    /**
     * ✅ UPDATED: Calculate minimum deposit required (50%)
     */
    public function getMinimumDepositAttribute(): float
    {
        if (!$this->billing) {
            return 0;
        }
        
        return round($this->billing->total_amount * 0.5, 2);
    }

    /**
     * Check if booking is within check-in window
     */
    public function isWithinCheckInWindow(): bool
    {
        $now = now();
        $checkInTime = $this->check_in_datetime;
        
        // Can check in up to 1 hour before scheduled time
        $earlyCheckInTime = $checkInTime->copy()->subHour();
        
        // Can check in up to 2 hours after scheduled time (grace period)
        $lateCheckInTime = $checkInTime->copy()->addHours(2);
        
        return $now->between($earlyCheckInTime, $lateCheckInTime);
    }

    /**
     * ✅ NEW: Get payment status from billing
     */
    public function getPaymentStatusAttribute(): ?string
    {
        return $this->billing?->payment_status;
    }

    /**
     * ✅ NEW: Get amount paid from billing
     */
    public function getAmountPaidAttribute(): float
    {
        return $this->billing?->amount_paid ?? 0;
    }

    /**
     * ✅ NEW: Get balance from billing
     */
    public function getBalanceAttribute(): float
    {
        return $this->billing?->balance ?? 0;
    }

    public function entranceRate()
    {
        return $this->belongsTo(Rate::class, 'entrance_rate_id');
    }

    /**
     * 🔧 ADD: Discount relationship
     */
    public function guestDiscounts()
    {
        return $this->belongsTo(Discount::class, 'discount_id');
    }

    public static function generateBookingReference(): string
    {
        $prefix = 'BOOK';
        $datePart = now()->format('Ymd');
        $randomPart = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        
        return "{$prefix}-{$datePart}-{$randomPart}";
    }
}