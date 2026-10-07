<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ElectionCandidate extends Model
{
    use HasFactory;

    protected $table = 'election_candidates';

    public $timestamps = false;

    protected $fillable = [
        'election_id',
        'candidate_code',
        'user_id',
        'name',
        'position',
        'party',
        'platform_tag',
        'votes_count',
        'status',
    ];

    public function election()
    {
        return $this->belongsTo(Election::class, 'election_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function votes()
    {
        return $this->hasMany(ElectionVote::class, 'candidate_id');
    }
}
