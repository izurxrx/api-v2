<?php

namespace App\Models;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use SoftDeletes, HasRoles;

    protected $fillable = [
        'username',
        'full_name',
        'contact_no',
        'password'
    ];

    protected $hidden = [
        'password',
    ];

    public function receivedPayments() {
        return $this->hasMany(Payment::class, 'received_by');
    }

    public function guestEntries() {
        return $this->hasMany(GuestEntry::class, 'created_by');
    }

    public function bookings() {
        return $this->hasMany(Booking::class, 'created_by');
    }
}

