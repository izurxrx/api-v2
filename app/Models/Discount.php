<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Discount extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'name',
        'description',
        'category',
        'type',
        'value',
        'valid_from',
        'valid_until',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'valid_from' => 'date',
        'valid_until' => 'date',
    ];

    // Activity Log Configuration
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'category', 'type', 'value', 'valid_from', 'valid_until'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // Relationships
    public function autoDiscountedDetails()
    {
        return $this->hasMany(GuestEntryDetail::class, 'auto_discount_id');
    }

    public function manualDiscountedDetails()
    {
        return $this->hasMany(GuestEntryDetail::class, 'manual_discount_id');
    }

    // Helper method to calculate discount amount based on base amount
    public function calculateDiscountAmount($baseAmount)
    {
        if ($this->type === 'Percentage') {
            return ($baseAmount * $this->value) / 100;
        }
        
        // Fixed_Amount
        return $this->value;
    }

    // Helper method to check if discount is currently valid
    public function isValid($date = null)
    {
        $checkDate = $date ? \Carbon\Carbon::parse($date) : now();
        
        if ($this->valid_from && $checkDate->lt($this->valid_from)) {
            return false;
        }
        
        if ($this->valid_until && $checkDate->gt($this->valid_until)) {
            return false;
        }
        
        return true;
    }
}