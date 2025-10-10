<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class GuestType extends Model
{
    use SoftDeletes;
    protected $table = 'guest_types';
    
    public $timestamps = true; // Make timestamps public

    protected $fillable = [
        'name',
        'description',
        'default_discount_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // Relationships
    public function defaultDiscount()
    {
        return $this->belongsTo(Discount::class, 'default_discount_id');
    }

    public function guestEntryDetails()
    {
        return $this->hasMany(GuestEntryDetail::class);
    }
}