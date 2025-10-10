<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class GuestEntry extends Model
{
    use SoftDeletes;

    public $table = 'guest_entries';
    
    public $timestamps = true; // Make timestamps public

    protected $fillable = [
        'entry_reference',
        'entry_date',
        'entry_time',
        'guest_name',
        'contact_number',
        'total_guests',
        'subtotal',
        'discount_amount',
        'total_amount',
        'payment_status',
        'amount_paid',
        'balance',
        'is_checked_out',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'entry_time' => 'datetime:H:i',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'balance' => 'decimal:2',
        'is_checked_out' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // Relationships
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function details()
    {
        return $this->hasMany(GuestEntryDetail::class);
    }

    public function payments()
    {
        return $this->morphMany(Payment::class, 'transaction', 'transaction_type', 'transaction_id')
                   ->where('transaction_type', 'Walk_In_Entry');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_checked_out', false);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('entry_date', today());
    }
}