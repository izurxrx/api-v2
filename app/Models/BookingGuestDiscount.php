<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingGuestDiscount extends Model
{
    protected $fillable = [
        'booking_id',
        'discount_id',
        'guest_type',
        'guest_count',
        'discount_amount',
    ];

    protected $casts = [
        'guest_count' => 'integer',
        'discount_amount' => 'decimal:2',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }
}