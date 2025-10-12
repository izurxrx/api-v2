<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Discount extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 
        'description', 
        'category', 
        'type', 
        'value', 
        'is_guest_type_discount', 
        'valid_from', 
        'valid_until'
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'is_guest_type_discount' => 'boolean'
    ];
}
