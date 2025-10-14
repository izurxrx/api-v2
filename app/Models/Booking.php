<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Booking extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'booking_reference',
        'guest_name',
        'contact_number',
        'facility_id',
        'check_in_date',
        'check_out_date',
        'check_in_time',
        'check_out_time',
        'actual_check_in_datetime',
        'checked_in_by',
        'actual_check_out_datetime',
        'checked_out_by',
        'number_of_guests',
        'guest_breakdown',
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
        'actual_check_in_datetime' => 'datetime',
        'actual_check_out_datetime' => 'datetime',
        'guest_breakdown' => 'array',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'third_party_service_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'balance' => 'decimal:2',
    ];

    // Activity Log Configuration
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'booking_reference', 'guest_name', 'booking_status', 
                'payment_status', 'total_amount', 'balance'
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // Relationships
    public function facility()
    {
        return $this->belongsTo(Facility::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function checkedInBy()
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    public function checkedOutBy()
    {
        return $this->belongsTo(User::class, 'checked_out_by');
    }

    public function payments()
    {
        return $this->morphMany(Payment::class, 'transaction', 'transaction_type', 'transaction_id')
                    ->where('transaction_type', 'Booking');
    }
}

