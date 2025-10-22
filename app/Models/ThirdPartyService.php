<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class ThirdPartyService extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'guest_entry_id',
        'service_name',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    // Relationships
    public function guestEntry()
    {
        return $this->belongsTo(GuestEntry::class);
    }

    // Activity Log Configuration
    protected static $logAttributes = ['service_name', 'amount'];
    protected static $logOnlyDirty = true;
    protected static $submitEmptyLogs = false;
    protected static $logName = 'third_party_service';

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['service_name', 'amount'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

}
