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
        'payment_status',
        'amount_paid',
        'balance',
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
        'amount_paid' => 'decimal:2',
        'balance' => 'decimal:2',
    ];

    // Activity Log Configuration
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'booking_reference', 'guest_name', 'booking_status', 
                'payment_status', 'total_amount', 'balance'
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // Relationships
    public function facility()
    {
        return $this->belongsTo(Facility::class);
    }

    /**
     * Get all facilities for this booking (NEW - Multi-facility support)
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

    public function payments()
    {
        return $this->morphMany(Payment::class, 'transaction', 'transaction_type', 'transaction_id')
                    ->where('transaction_type', 'Booking');
    }

    // Helper Methods
    
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
     * Check if booking requires payment
     */
    public function requiresPayment(): bool
    {
        return $this->balance > 0;
    }

    /**
     * Check if minimum deposit (50%) is paid
     */
    public function hasMinimumDeposit(): bool
    {
        $minimumDeposit = $this->total_amount * 0.5;
        return $this->amount_paid >= $minimumDeposit;
    }

    /**
     * Calculate minimum deposit required (50%)
     */
    public function getMinimumDepositAttribute(): float
    {
        return round($this->total_amount * 0.5, 2);
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
}