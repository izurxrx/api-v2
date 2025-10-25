<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Facility extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'facility_type_id',
        'requires_entrance',
        'name',
        'quantity',
        'expected_capacity',
        'max_capacity',
        'description',
    ];

    protected $casts = [
        'requires_entrance' => 'boolean',
    ];

    // Activity Log Configuration
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'quantity', 'requires_entrance'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // Relationships
    public function facilityType()
    {
        return $this->belongsTo(FacilityType::class);
    }

    public function rates()
    {
        return $this->hasMany(Rate::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function guestEntryFacilities()
    {
        return $this->hasMany(GuestEntryFacility::class);
    }
}