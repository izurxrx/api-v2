<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class GuestEntryDetail extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'guest_entry_id',
        'guest_type_name',
        'rate_id',
        'guest_count',
        'base_rate',
        'discount_mode',
        'discount_id',
        'discount_amount',
        'final_rate',
        'total_amount',
    ];

    protected $casts = [
        'base_rate' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'final_rate' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['guest_type_name', 'guest_count', 'base_rate', 'final_rate', 'total_amount'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function guestEntry()
    {
        return $this->belongsTo(GuestEntry::class);
    }

    public function rate()
    {
        return $this->belongsTo(Rate::class);
    }

    public function discount()
    {
        return $this->belongsTo(Discount::class, 'discount_id');
    }

    public function calculateAmounts()
    {
        $this->final_rate = $this->base_rate - $this->discount_amount;
        $this->total_amount = $this->final_rate * $this->guest_count;
    }
}