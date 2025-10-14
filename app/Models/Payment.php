<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Payment extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'transaction_reference',
        'transaction_type',
        'transaction_id',
        'payment_date',
        'payment_time',
        'payment_method',
        'amount_paid',
        'change_amount',
        'received_by',
        'payment_reference',
        'notes',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount_paid' => 'decimal:2',
        'change_amount' => 'decimal:2',
    ];

    // Activity Log Configuration
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['transaction_reference', 'payment_method', 'amount_paid'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // Relationships
    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    // Get the transaction (Booking or GuestEntry)
    public function transaction()
    {
        if ($this->transaction_type === 'Booking') {
            return $this->belongsTo(Booking::class, 'transaction_id');
        }
        return $this->belongsTo(GuestEntry::class, 'transaction_id');
    }
}