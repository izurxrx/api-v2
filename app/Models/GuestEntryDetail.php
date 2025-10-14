<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class GuestEntryDetail extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'guest_entry_id',
        'rate_id',
        'guest_type_id',
        'guest_count',
        'base_rate',
        'auto_discount_id',
        'auto_discount_amount',
        'manual_discount_id',
        'manual_discount_amount',
        'final_rate',
        'total_amount',
    ];

    protected $casts = [
        'base_rate' => 'decimal:2',
        'auto_discount_amount' => 'decimal:2',
        'manual_discount_amount' => 'decimal:2',
        'final_rate' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    // Activity Log Configuration
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['guest_count', 'base_rate', 'final_rate', 'total_amount'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // Relationships
    public function guestEntry()
    {
        return $this->belongsTo(GuestEntry::class);
    }

    public function rate()
    {
        return $this->belongsTo(Rate::class);
    }

    public function guestType()
    {
        return $this->belongsTo(GuestType::class);
    }

    public function autoDiscount()
    {
        return $this->belongsTo(Discount::class, 'auto_discount_id');
    }

    public function manualDiscount()
    {
        return $this->belongsTo(Discount::class, 'manual_discount_id');
    }
}