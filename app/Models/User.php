<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'email_hash',
        'phone',
        'nid_no',
        'address',
        'password',
        'mac',
        'rsa_public_key',
        'ecc_public_key',
        'google2fa_secret',
        'session_token',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'google2fa_secret',
        'session_token',
        'rsa_public_key',
        'ecc_public_key',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function isAdmin(): bool
    {
        return $this->email_hash === hash('sha256', strtolower('admin@gmail.com'));
    }
}
