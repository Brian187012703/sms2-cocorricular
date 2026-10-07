<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ElectionVote extends Model
{
    use HasFactory;

    protected $table = 'election_votes';

    public $timestamps = false;

    protected $fillable = [
        'election_id',
        'candidate_id',
        'position',
        'vote_hash',
        'votes_json',
        'cast_at',
    ];

    protected $casts = [
        'cast_at' => 'datetime',
    ];

    public function election()
    {
        return $this->belongsTo(Election::class, 'election_id');
    }

    public function candidate()
    {
        return $this->belongsTo(ElectionCandidate::class, 'candidate_id');
    }
}
