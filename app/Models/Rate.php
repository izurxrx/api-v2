<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Rate extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'facility_id', 
        'rate_name', 
        'rate_category', 
        'rate_type', 
        'base_price', 
        'duration', 
        'extension_fee'
    ];

    public function facility() {
        return $this->belongsTo(Facility::class, 'facility_id');
    }
}
