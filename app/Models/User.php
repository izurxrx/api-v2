<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, SoftDeletes, LogsActivity;

    protected $guard_name = 'api';
    
    protected $fillable = [
        'username',
        'full_name',
        'contact_no',
        'password',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'password' => 'hashed',
    ];

    // Activity Log Configuration
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['username', 'full_name', 'contact_no'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // Relationships
    public function createdBookings()
    {
        return $this->hasMany(Booking::class, 'created_by');
    }

    public function createdGuestEntries()
    {
        return $this->hasMany(GuestEntry::class, 'created_by');
    }

    public function receivedPayments()
    {
        return $this->hasMany(Payment::class, 'received_by');
    }

    public function checkedInBookings()
    {
        return $this->hasMany(Booking::class, 'checked_in_by');
    }

    public function checkedOutBookings()
    {
        return $this->hasMany(Booking::class, 'checked_out_by');
    }

        public function isAdmin(): bool
    {
        return $this->hasRole('Admin');
    }

    public function isManager(): bool
    {
        return $this->hasRole('Manager');
    }

    public function isStaff(): bool
    {
        return $this->hasRole('Staff');
    }
}