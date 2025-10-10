<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Booking extends Model
{
    use SoftDeletes;
    
    public $table = 'bookings';

    public $timestamps = true;
    protected $fillable = [
        'booking_reference',
        'guest_name',
        'contact_number',
        'facility_id',
        'check_in_date',
        'check_out_date',
        'check_in_time',
        'check_out_time',
        'number_of_guests',
        'booking_status',
        'subtotal',
        'discount_amount',
        'third_party_service_amount',
        'total_amount',
        'payment_status',
        'amount_paid',
        'balance',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'check_in_date' => 'date',
        'check_out_date' => 'date',
        'check_in_time' => 'datetime:H:i',
        'check_out_time' => 'datetime:H:i',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'third_party_service_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'balance' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // Relationships
    public function facility()
    {
        return $this->belongsTo(Facility::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function services()
    {
        return $this->hasMany(BookingService::class);
    }

    public function payments()
    {
        return $this->morphMany(Payment::class, 'transaction', 'transaction_type', 'transaction_id')
                   ->where('transaction_type', 'Booking');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('booking_status', 'Pending');
    }

    public function scopeConfirmed($query)
    {
        return $query->where('booking_status', 'Confirmed');
    }

    public function scopeUnpaid($query)
    {
        return $query->where('payment_status', 'Unpaid');
    }
}