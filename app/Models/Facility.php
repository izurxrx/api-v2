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
        'booking_type',
        'name',
        'quantity',
        'expected_capacity',
        'max_capacity',
        'description',
        'is_maintenance',
        'is_available_for_booking',
    ];

    protected $casts = [
        'is_maintenance' => 'boolean',
        'is_available_for_booking' => 'boolean',
    ];

    // Activity Log Configuration
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'quantity', 'is_maintenance', 'is_available_for_booking'])
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