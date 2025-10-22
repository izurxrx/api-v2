<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookingFacility extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'facility_id',
        'rate_id',
        'start_datetime',
        'end_datetime',
        'duration_hours',
        'base_amount',
        'quantity',
    ];

    protected $casts = [
        'start_datetime' => 'datetime',
        'end_datetime' => 'datetime',
        'base_amount' => 'decimal:2',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function facility()
    {
        return $this->belongsTo(Facility::class);
    }

    public function rate()
    {
        return $this->belongsTo(Rate::class);
    }
}