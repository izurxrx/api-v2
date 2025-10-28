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
        'billing_id', // ✅ NEW: Link to billing instead of polymorphic
        'payment_number',
        'amount',
        'change_amount',
        'payment_method',
        'payment_type',
        'reference_number',
        'notes',
        'payment_date',
        'received_by',
    ];

    protected $casts = [
        'payment_date' => 'datetime',
        'amount' => 'decimal:2',
        'change_amount' => 'decimal:2',
    ];

    // ========================================
    // RELATIONSHIPS
    // ========================================

    /**
     * ✅ NEW: Belongs to billing
     */
    public function billing()
    {
        return $this->belongsTo(Billing::class);
    }

    /**
     * Staff who received the payment
     */
    public function receivedBy()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    // ========================================
    // ACCESSOR ATTRIBUTES
    // ========================================

    /**
     * Get guest name from billing's billable
     */
    public function getGuestNameAttribute(): ?string
    {
        if (!$this->billing || !$this->billing->billable) {
            return null;
        }
        
        return $this->billing->billable->guest_name ?? null;
    }

    /**
     * Get transaction reference
     */
    public function getTransactionReferenceAttribute(): ?string
    {
        if (!$this->billing || !$this->billing->billable) {
            return null;
        }
        
        $billable = $this->billing->billable;
        
        if ($billable instanceof Booking) {
            return $billable->booking_reference;
        } elseif ($billable instanceof GuestEntry) {
            return $billable->entry_reference;
        }
        
        return null;
    }

    // ========================================
    // ACTIVITY LOG
    // ========================================

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['payment_number', 'payment_method', 'amount', 'payment_type'])
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
            $query->where('payment_method', $method);
        }
        return $query;
    }

    /**
     * Scope for filtering by payment type
     */
    public function scopeByType($query, $type)
    {
        if ($type && $type !== 'all') {
            $query->where('payment_type', $type);
        }
        return $query;
    }

    public static function generatePaymentNumber()
    {
        $prefix = 'PAY';
        $datePart = now()->format('Ymd');
        $randomPart = strtoupper(substr(uniqid(), -5));
        return "{$prefix}-{$datePart}-{$randomPart}";
    }
}