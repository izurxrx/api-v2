<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Rate extends Model
{
    use SoftDeletes;

    protected $table = 'rates';

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
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // Relationships
    public function facility()
    {
        return $this->belongsTo(Facility::class);
    }

    public function guestEntryDetails()
    {
        return $this->hasMany(GuestEntryDetail::class);
    }

    // Methods
    public function calculateTimeBill($actualHours)
    {
        if ($this->rate_type !== 'Time_Based' || $actualHours <= $this->duration) {
            return $this->base_price;
        }

        $extraHours = $actualHours - $this->duration;
        return $this->base_price + ($extraHours * $this->extension_fee);
    }

    // Scopes
    public function scopeEntrance($query)
    {
        return $query->where('rate_category', 'Entrance');
    }

    public function scopeFacility($query)
    {
        return $query->where('rate_category', 'Facility');
    }
}