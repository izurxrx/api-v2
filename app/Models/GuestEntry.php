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
        'payment_status',
        'amount_paid',
        'balance',
        'is_checked_out',
        'checkout_datetime',
        'notes',
        'created_by',
        'payment_method',
        'exit_date',
        'exit_time',
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
        'amount_paid' => 'decimal:2',
        'balance' => 'decimal:2',
        'is_checked_out' => 'boolean',
        'exit_date' => 'date',
        'exit_time' => 'datetime:H:i:s',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'entry_reference', 'guest_name', 'total_guests', 
                'total_amount', 'payment_status', 'is_checked_out'
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // Relationships
    public function details()
    {
        return $this->hasMany(GuestEntryDetail::class);
    }

    public function facilities()
    {
        return $this->hasMany(GuestEntryFacility::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'transaction_id')
                    ->where('transaction_type', 'GuestEntry');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function discount()
    {
        return $this->belongsTo(Discount::class, 'discount_id');
    }

    public function thirdPartyServices()
    {
        return $this->hasMany(ThirdPartyService::class);
    }
    // Helper method to recalculate totals
    public function recalculateTotals()
    {
        $this->entrance_subtotal = $this->details()->sum('total_amount');
        $this->facility_subtotal = $this->facilities()->sum('subtotal');
        $this->subtotal = $this->entrance_subtotal + $this->facility_subtotal;
        $this->total_amount = $this->subtotal - $this->discount_amount;
        $this->balance = $this->total_amount - $this->amount_paid;
        
        if ($this->balance <= 0) {
            $this->payment_status = 'Paid';
        } elseif ($this->amount_paid > 0) {
            $this->payment_status = 'Partial';
        } else {
            $this->payment_status = 'Unpaid';
        }
        
        $this->save();
    }

       // Relationships
    public function facilityType()
    {
        return $this->belongsTo(FacilityType::class);
    }

    public function rates()
    {
        return $this->hasMany(Rate::class);
    }

    public function scopeWalkIn($query)
    {
        return $query->where('booking_type', 'walk_in');
    }

    public function scopeBooking($query)
    {
        return $query->where('booking_type', 'booking');
    }

    public function scopeAvailableForWalkIn($query)
    {
        return $query->where('booking_type', 'walk_in')
                     ->where('is_available_for_booking', true)
                     ->where('is_maintenance', false);
    }
    
}