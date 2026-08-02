<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Achievement extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'club_id',
        'submitted_by',
        'title',
        'competition',
        'award_date',
        'proof_file',
        'status',
        'verified_by',
        'notes',
    ];

    protected $casts = [
        'award_date' => 'date',
        'created_at' => 'datetime',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
