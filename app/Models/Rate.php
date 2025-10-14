<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Rate extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'facility_id',
        'rate_name',
        'rate_category',
        'rate_type',
        'base_price',
        'duration',
        'extension_fee',
    ];

    protected $casts = [
        'base_price' => 'decimal:2',
        'extension_fee' => 'decimal:2',
    ];

    // Activity Log Configuration
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['rate_name', 'base_price', 'duration', 'extension_fee'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // Relationships
    public function facility()
    {
        return $this->belongsTo(Facility::class);
    }

    public function guestEntryDetails()
    {
        return $this->hasMany(GuestEntryDetail::class);
    }

    public function guestEntryFacilities()
    {
        return $this->hasMany(GuestEntryFacility::class);
    }
}