<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class GuestEntryDetail extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'guest_entry_id', 
        'rate_id', 
        'guest_type_id', 
        'guest_count',
        'base_rate', 
        'auto_discount_id', 
        'auto_discount_amount',
        'manual_discount_id', 
        'manual_discount_amount', 
        'final_rate', 
        'total_amount'
    ];

    public function guestEntry() {
        return $this->belongsTo(GuestEntry::class, 'guest_entry_id');
    }

    public function rate() {
        return $this->belongsTo(Rate::class, 'rate_id');
    }

    public function guestType() {
        return $this->belongsTo(GuestType::class, 'guest_type_id');
    }

    public function autoDiscount() {
        return $this->belongsTo(Discount::class, 'auto_discount_id');
    }

    public function manualDiscount() {
        return $this->belongsTo(Discount::class, 'manual_discount_id');
    }
}
