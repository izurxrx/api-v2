<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Facility extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'facility_type_id', 
        'name', 
        'quantity', 
        'expected_capacity', 
        'max_capacity', 
        'description', 
        'is_maintenance', 
        'is_available_for_booking'
    ];

    public function type() {
        return $this->belongsTo(FacilityType::class, 'facility_type_id');
    }

    public function guestEntries() {
        return $this->hasMany(GuestEntryFacility::class, 'facility_id');
    }

    public function rates() {
        return $this->hasMany(Rate::class, 'facility_id');
    }
}
