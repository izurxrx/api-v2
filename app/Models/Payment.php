<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use SoftDeletes;

    public $table = 'payments';
    
    public $timestamps = true; // Make timestamps public

    protected $fillable = [
        'transaction_reference',
        'transaction_type',
        'transaction_id',
        'payment_date',
        'payment_time',
        'payment_method',
        'amount_paid',
        'received_by',
        'notes',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'payment_time' => 'datetime:H:i',
        'amount_paid' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // Relationships
    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class, 'transaction_id')
                   ->where('transaction_type', 'Booking');
    }

    public function guestEntry()
    {
        return $this->belongsTo(GuestEntry::class, 'transaction_id')
                   ->where('transaction_type', 'Walk_In_Entry');
    }
}