<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ElectionVoter extends Model
{
    use HasFactory;

    protected $table = 'election_voters';

    public $timestamps = false;

    protected $fillable = [
        'election_id',
        'user_id',
        'voted_at',
        'ip_address',
    ];

    protected $casts = [
        'voted_at' => 'datetime',
    ];

    public function election()
    {
        return $this->belongsTo(Election::class, 'election_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
