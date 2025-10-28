<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class GuestEntry extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'booking_id', // ✅ NEW: Link to booking
        'entry_type', // ✅ NEW: 'walk_in' or 'booking'
        'entry_reference',
        'entry_date',
        'entry_time',
        'check_in_datetime',
        'discount_mode',
        'discount_id',
        'manual_discount_amount', 
        'guest_name',
        'contact_number',
        'total_guests',
        'entrance_subtotal',
        'facility_subtotal',
        'third_party_service_amount',
        'subtotal',
        'discount_amount',
        'total_amount',
        // ❌ REMOVED: payment_status, amount_paid, balance, payment_method
        'is_checked_out',
        'checkout_datetime',
        'notes',
        'created_by',
        'exit_date',
        'exit_time',
        'checked_out_by',
        'checkout_notes',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'entry_time' => 'datetime',
        'check_in_datetime' => 'datetime',
        'checkout_datetime' => 'datetime',
        'entrance_subtotal' => 'decimal:2',
        'facility_subtotal' => 'decimal:2',
        'third_party_service_amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'manual_discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        // ❌ REMOVED: amount_paid, balance casts
        'is_checked_out' => 'boolean',
        'exit_date' => 'date',
        'exit_time' => 'datetime:H:i:s',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'entry_reference', 'guest_name', 'total_guests', 
                'total_amount', 'is_checked_out' // Removed payment_status
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // ========================================
    // RELATIONSHIPS
    // ========================================
    
    public function guestdetails()
    {
        return $this->hasMany(GuestEntryDetail::class);
    }

    public function facilities()
    {
        return $this->hasMany(GuestEntryFacility::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function checkedOutBy()
    {
        return $this->belongsTo(User::class, 'checked_out_by');
    }

    public function entranceRate()
    {
        return $this->belongsTo(Rate::class, 'entrance_rate_id');
    }

    public function discount()
    {
        return $this->belongsTo(Discount::class, 'discount_id');
    }

    public function thirdPartyServices()
    {
        return $this->hasMany(ThirdPartyService::class);
    }
    
    /**
     * ✅ NEW: Polymorphic relationship to billing
     */
    public function billing()
    {
        return $this->morphOne(Billing::class, 'billable');
    }

    /**
     * ✅ NEW: Relationship to booking (for check-ins from bookings)
     */
    public function booking()
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

    // ========================================
    // HELPER METHODS
    // ========================================
    
    /**
     * ✅ UPDATED: Recalculate totals (removed payment logic)
     */
    public function recalculateTotals()
    {
        $this->entrance_subtotal = $this->details()->sum('total_amount');
        $this->facility_subtotal = $this->facilities()->sum('subtotal');
        $this->subtotal = $this->entrance_subtotal + $this->facility_subtotal;
        $this->total_amount = $this->subtotal - $this->discount_amount;
        
        $this->save();
        
        // ✅ Update billing if exists
        if ($this->billing) {
            $this->billing->updateTotals(
                $this->subtotal,
                $this->discount_amount,
                $this->total_amount
            );
        }
    }

    // ========================================
    // ACCESSOR ATTRIBUTES (for backward compatibility)
    // ========================================
    
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

    // ========================================
    // SCOPES
    // ========================================
    
    public function scopeWalkIn($query)
    {
        return $query->where('booking_type', 'walk_in');
    }

    public function scopeBooking($query)
    {
        return $query->where('booking_type', 'booking');
    }

    // public function scopeAvailableForWalkIn($query)
    // {
    //     return $query->where('booking_type', 'walk_in')
    //                  ->where('is_available_for_booking', true)
    //                  ->where('is_maintenance', false);
    // }

    public static function generateEntryReference()
    {
        $prefix = 'SWIM';
        $datePart = now()->format('Ymd');
        $randomPart = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        return "{$prefix}-{$datePart}-{$randomPart}";
    }
}