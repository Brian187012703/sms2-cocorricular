<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'users';

    protected $fillable = [
        'username',
        'first_name',
        'last_name',
        'email',
        'role',
        'status',
        'password_hash',
        'profile_pic',
        'last_login',
        'last_password_change',
        'last_mfa_verified_at',
    ];

    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    protected $casts = [
        'last_login' => 'datetime',
        'last_password_change' => 'datetime',
        'last_mfa_verified_at' => 'datetime',
    ];

    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    public function getPasswordAttribute()
    {
        return $this->password_hash;
    }

    public function setPasswordAttribute($value)
    {
        $this->attributes['password_hash'] = $value;
    }

    public function student()
    {
        return $this->hasOne(Student::class, 'user_id');
    }

    public function advisedClub()
    {
        return $this->hasOne(Club::class, 'adviser_user_id');
    }

    public function memberships()
    {
        return $this->hasMany(ClubMembership::class, 'user_id');
    }

    public function attendanceLogs()
    {
        return $this->hasMany(AttendanceLog::class, 'user_id');
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isSsc(): bool
    {
        return $this->role === 'ssc';
    }

    public function isClubAdviser(): bool
    {
        return $this->role === 'club_adviser';
    }

    public function isStudent(): bool
    {
        return $this->role === 'student';
    }
}
