<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class GuestEntryFacility extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'guest_entry_id', 'facility_id', 'rate_id', 
        'start_datetime', 'end_datetime', 'duration_hours', 
        'base_amount', 'extension_hours', 'extension_amount', 'subtotal'
    ];

    protected $casts = [
        'start_datetime' => 'datetime',
        'end_datetime' => 'datetime',
        'duration_hours' => 'decimal:2',
        'base_amount' => 'decimal:2',
        'extension_amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    public function guestEntry() {
        return $this->belongsTo(GuestEntry::class, 'guest_entry_id');
    }

    public function facility() {
        return $this->belongsTo(Facility::class, 'facility_id');
    }

    public function rate() {
        return $this->belongsTo(Rate::class, 'rate_id');
    }
}

