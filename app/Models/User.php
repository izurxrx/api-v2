<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Authenticatable
{
    use HasApiTokens, SoftDeletes;
    
    protected $table = 'users';

    public $timestamps = true;

    public const ROLES = ['Admin', 'Manager', 'Staff'];

    protected $fillable = [
        'username',
        'full_name', 
        'contact_no',
        'password',
        'role',
    ];
    
    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'password' => 'hashed',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // Relationships
    public function bookings()
    {
        return $this->hasMany(Booking::class, 'created_by');
    }

    public function guestEntries()
    {
        return $this->hasMany(GuestEntry::class, 'created_by');
    }

    public function paymentsReceived()
    {
        return $this->hasMany(Payment::class, 'received_by');
    }

    public function isAdmin(): bool
    {
        return $this->role === 'Admin';
    }

    public function isManager(): bool
    {
        return $this->role === 'Manager';
    }

    public function isStaff(): bool
    {
        return $this->role === 'Staff';
    }
}
