<?php

namespace App\Models;

use App\Models\GuestEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User;

class Payment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'guest_entry_id',
        'amount',
        'payment_type',
        'received_by', 
        'payment_date'
    ];

    protected $casts = [
        'payment_date' => 'datetime',
        'amount' => 'decimal:2',
    ];

    public function guestEntry() {
        return $this->belongsTo(GuestEntry::class, 'guest_entry_id');
    }

    public function receiver() {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function isDownpayment(): bool {
        return strtolower($this->payment_type) === 'downpayment';
    }
}
