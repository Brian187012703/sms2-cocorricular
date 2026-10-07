<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MfaCode extends Model
{
    use HasFactory;

    protected $table = 'mfa_codes';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'email',
        'code',
        'purpose',
        'expires_at',
        'is_used',
        'attempts',
        'max_attempts',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'is_used' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
