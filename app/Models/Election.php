<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Election extends Model
{
    use HasFactory;

    protected $table = 'elections';

    public $timestamps = false;

    protected $fillable = [
        'election_code',
        'club_id',
        'scope',
        'title',
        'description',
        'election_type',
        'starts_at',
        'closes_at',
        'status',
        'eligible_voters',
        'positions',
        'created_by',
        'verified_at',
        'verified_by',
        'audit_notes',
        'created_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'closes_at' => 'datetime',
        'verified_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function canVote(User $user): bool
    {
        return $user->role === 'student' && $user->status === 'Active'
            && $this->status === 'active' && $this->starts_at && $this->closes_at
            && now()->betweenIncluded($this->starts_at, $this->closes_at)
            && ($this->scope !== 'Club' || $user->memberships()->where('club_id', $this->club_id)->where('status', 'Active')->exists());
    }

    public function club()
    {
        return $this->belongsTo(Club::class, 'club_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function candidates()
    {
        return $this->hasMany(ElectionCandidate::class, 'election_id');
    }

    public function votes()
    {
        return $this->hasMany(ElectionVote::class, 'election_id');
    }

    public function voters()
    {
        return $this->hasMany(ElectionVoter::class, 'election_id');
    }
}
