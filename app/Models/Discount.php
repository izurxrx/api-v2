<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Carbon\Carbon;

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
        'is_active',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'is_active' => 'boolean',
    ];

    // Activity Log Configuration
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'category', 'type', 'value', 'valid_from', 'valid_until'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function guestEntryDetails()
    {
        return $this->hasMany(GuestEntryDetail::class, 'discount_id');
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
    public function isActive(): bool
    {
        // Get current date (start of day for comparison)
        $today = Carbon::now()->startOfDay();

        // If manually disabled in database, return false immediately
        if (!$this->is_active) {
            return false;
        }

        // Parse the date fields
        $validFrom = $this->valid_from ? Carbon::parse($this->valid_from)->startOfDay() : null;
        $validUntil = $this->valid_until ? Carbon::parse($this->valid_until)->endOfDay() : null;

        // If no date restrictions, just check is_active flag
        if (!$validFrom && !$validUntil) {
            return true;
        }

        // Check if current date is after start date (or no start date)
        $afterStart = !$validFrom || $today->greaterThanOrEqualTo($validFrom);
        
        // Check if current date is before end date (or no end date)
        $beforeEnd = !$validUntil || $today->lessThanOrEqualTo($validUntil);

        // Discount is active only if both conditions are true
        return $afterStart && $beforeEnd;
    }

    public function scopeActive($query)
    {
        $today = Carbon::now()->startOfDay();

        return $query->where('is_active', true)
            ->where(function ($q) use ($today) {
                $q->where(function ($dateQuery) use ($today) {
                    // valid_from is null OR valid_from <= today
                    $dateQuery->whereNull('valid_from')
                              ->orWhere('valid_from', '<=', $today);
                })
                ->where(function ($dateQuery) use ($today) {
                    // valid_until is null OR valid_until >= today
                    $dateQuery->whereNull('valid_until')
                              ->orWhere('valid_until', '>=', $today);
                });
            });
    }
}