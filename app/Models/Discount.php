<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class Discount extends Model
{
    use SoftDeletes;

    protected $table = 'discounts';

    public $timestamps = true; // Make timestamps public

    protected $fillable = [
        'name',
        'description',
        'category',
        'type',
        'value',
        'is_guest_type_discount',
        'valid_from',
        'valid_until',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'is_guest_type_discount' => 'boolean',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // Relationships
    public function guestTypes()
    {
        return $this->hasMany(GuestType::class, 'default_discount_id');
    }

    public function autoDiscountEntries()
    {
        return $this->hasMany(GuestEntryDetail::class, 'auto_discount_id');
    }

    public function manualDiscountEntries()
    {
        return $this->hasMany(GuestEntryDetail::class, 'manual_discount_id');
    }

    public function calculateDiscount($amount)
    {
        if ($this->type === 'Percentage') {
            return ($amount * $this->value) / 100;
        }
        
        return $this->value;
    }

    public function getIsActiveAttribute(): bool
    {
        if ($this->category !== 'Seasonal_Discount' || !$this->valid_from || !$this->valid_until) {
            return true;
        }
        $today = now()->toDateString();
        return $this->valid_from->toDateString() <= $today && $this->valid_until->toDateString() >= $today;
    }

}