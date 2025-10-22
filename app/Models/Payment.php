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
        'transaction_type',
        'transaction_id',
        'transaction_reference',
        'payment_method',
        'amount_paid',
        'change_amount',
        'payment_reference',
        'notes',
        'payment_date',
        'payment_time',
        'received_by',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount_paid' => 'decimal:2',
        'change_amount' => 'decimal:2',
    ];

    // ========================================
    // RELATIONSHIPS
    // ========================================

    /**
     * Staff who received the payment
     */
    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * Booking transaction (if transaction_type is Booking)
     * ✅ FIXED: Remove where clause
     */
    public function booking()
    {
        return $this->belongsTo(Booking::class, 'transaction_id');
    }

    /**
     * Guest Entry transaction (if transaction_type is GuestEntry)
     * ✅ FIXED: Remove where clause
     */
    public function guestEntry()
    {
        return $this->belongsTo(GuestEntry::class, 'transaction_id');
    }

    /**
     * Get the transaction (booking or guest entry) dynamically
     */
    public function getTransactionAttribute()
    {
        if ($this->transaction_type === 'Booking') {
            return $this->booking;
        } elseif ($this->transaction_type === 'GuestEntry') {
            return $this->guestEntry;
        }
        return null;
    }

    /**
     * Get guest name from the related transaction
     */
    public function getGuestNameAttribute()
    {
        if ($this->transaction_type === 'Booking' && $this->booking) {
            return $this->booking->guest_name;
        } elseif ($this->transaction_type === 'GuestEntry' && $this->guestEntry) {
            return $this->guestEntry->guest_name;
        }
        return null;
    }

    // ========================================
    // ACTIVITY LOG
    // ========================================

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['transaction_reference', 'payment_method', 'amount_paid'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // ========================================
    // SCOPES
    // ========================================

    /**
     * Scope for filtering by date range
     */
    public function scopeDateRange($query, $from, $to)
    {
        if ($from) {
            $query->whereDate('payment_date', '>=', $from);
        }
        if ($to) {
            $query->whereDate('payment_date', '<=', $to);
        }
        return $query;
    }

    /**
     * Scope for filtering by payment method
     */
    public function scopeByMethod($query, $method)
    {
        if ($method && $method !== 'all') {
            $query->where('payment_method', 'like', "%{$method}%");
        }
        return $query;
    }

    /**
     * Scope for filtering by transaction type
     */
    public function scopeByTransactionType($query, $type)
    {
        if ($type && $type !== 'all') {
            $query->where('transaction_type', $type);
        }
        return $query;
    }
}