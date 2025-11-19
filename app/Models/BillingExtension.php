<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingExtension extends Model
{
    protected $fillable = [
        'billing_id',
        'facility_id',
        'rate_id',
        'discount_id',
        'extension_type',
        'is_overtime',
        'is_released',
        'released_at',
        'released_by',
        'description',
        'facility_start_datetime',
        'facility_end_datetime',
        'amount',
        'hours',
        'discount_amount',
        'quantity',
        'total_amount',
        'metadata',
        'added_by',
    ];

    protected $casts = [
        'metadata' => 'array',
        'amount' => 'decimal:2',
        'hours' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'is_overtime' => 'boolean',
        'is_released' => 'boolean',
        'facility_start_datetime' => 'datetime',
        'facility_end_datetime' => 'datetime',
        'released_at' => 'datetime',
    ];

    /**
     * Get the billing that owns this extension
     */
    public function billing(): BelongsTo
    {
        return $this->belongsTo(Billing::class);
    }

    /**
     * Get the facility (for overtime tracking)
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    /**
     * Get the rate (for overtime calculation)
     */
    public function rate(): BelongsTo
    {
        return $this->belongsTo(Rate::class);
    }

    /**
     * Get the discount applied (if any)
     */
    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    /**
     * Get the user who added this extension
     */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    /**
     * Get the user who released this facility
     */
    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }
}
