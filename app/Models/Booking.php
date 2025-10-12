<?php

namespace App\Models;

use App\Models\Facility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User;

class Booking extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'booking_reference', 
        'guest_name', 
        'contact_number', 
        'facility_id', 
        'check_in_date', 
        'check_out_date', 
        'check_in_time', 
        'check_out_time',
        'number_of_guests', 
        'subtotal', 
        'discount_amount', 
        'total_amount',
        'payment_status', 
        'amount_paid', 
        'balance', 
        'notes', 
        'created_by'
    ];

    // Relationships
    public function facility() {
        return $this->belongsTo(Facility::class);
    }

    public function creator() {
        return $this->belongsTo(User::class, 'created_by');
    }
}
