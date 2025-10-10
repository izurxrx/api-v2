<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BookingService extends Model
{
    use SoftDeletes;

    public $table = 'booking_services';
    
    public $timestamps = true; // Make timestamps public

    protected $fillable = [
        'booking_id',
        'service_name',
        'service_provider',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // Relationships
    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}