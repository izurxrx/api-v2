<?php

namespace App\Models;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class GuestEntry extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'entry_reference', 
        'entry_date', 
        'entry_time', 
        'guest_name', 
        'contact_number',
        'total_guests', 
        'subtotal', 
        'discount_amount', 
        'total_amount', 
        'payment_status', 
        'amount_paid', 
        'balance', 
        'is_checked_out', 
        'notes', 
        'created_by'
    ];

    protected $casts = [
        'entry_date' => 'date',
        'entry_time' => 'datetime:H:i:s',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'balance' => 'decimal:2'
    ];

    // Relationships
    public function createdBy() {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function details() {
        return $this->hasMany(GuestEntryDetail::class, 'guest_entry_id');
    }

    public function facilities() {
        return $this->hasMany(GuestEntryFacility::class, 'guest_entry_id');
    }

    public function payments() {
        return $this->hasMany(Payment::class, 'guest_entry_id');
    }

    public function downpayments() {
        return $this->payments()->where('payment_type', 'downpayment');
    }

    public function fullPayments() {
        return $this->payments()->where('payment_type', 'full');
    }

    // Helper: total paid
    public function totalPaid(): float {
        return $this->payments()->sum('amount');
    }

    public function remainingBalance(): float {
        return $this->total_amount - $this->totalPaid();
    }
}

