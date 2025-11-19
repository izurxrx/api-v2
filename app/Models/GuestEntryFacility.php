<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class GuestEntryFacility extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'guest_entry_id',
        'facility_id',
        'rate_id',
        'start_datetime',
        'end_datetime',
        'duration_hours',
        'base_amount',
        'extension_hours',
        'extension_amount',
        'subtotal',
        'is_released',
        'released_at',
        'released_by',
    ];

    protected $casts = [
        'start_datetime' => 'datetime',
        'end_datetime' => 'datetime',
        'duration_hours' => 'decimal:2',
        'base_amount' => 'decimal:2',
        'extension_hours' => 'decimal:2',
        'extension_amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'is_released' => 'boolean',
        'released_at' => 'datetime',
    ];

    // Activity Log Configuration
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['start_datetime', 'end_datetime', 'duration_hours', 'subtotal'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // Relationships
    public function guestEntry()
    {
        return $this->belongsTo(GuestEntry::class);
    }

    public function facility()
    {
        return $this->belongsTo(Facility::class);
    }

    public function rate()
    {
        return $this->belongsTo(Rate::class);
    }

    public function releasedBy()
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}
