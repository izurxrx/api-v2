<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Facility extends Model
{
    use SoftDeletes;

    protected $table = 'facilities';
    public $timestamps = true;

    protected $fillable = [
        'facility_type_id',
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
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

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

    // Scopes
    public function scopeAvailable($query)
    {
        return $query->where('is_available_for_booking', true)
                     ->where('is_maintenance', false);
    }

    public function scopeForBooking($query)
    {
        return $query->available()->where('quantity', '>', 0);
    }

    public function scopeSearch($query, $term)
    {
        $term = strtolower($term);
        return $query->where(function ($q) use ($term) {
            $q->whereRaw('LOWER(name) LIKE ?', ["%{$term}%"])
              ->orWhereRaw('LOWER(description) LIKE ?', ["%{$term}%"]);
        });
    }
}
