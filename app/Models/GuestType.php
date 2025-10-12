<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class GuestType extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 
        'description', 
        'default_discount_id'
    ];

    public function defaultDiscount() {
        return $this->belongsTo(Discount::class, 'default_discount_id');
    }

    public function guestEntryDetails() {
        return $this->hasMany(GuestEntryDetail::class, 'guest_type_id');
    }
}
