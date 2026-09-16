<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Achievement extends Model
{
    use HasFactory;

    protected $table = 'achievements';

    protected $fillable = [
        'club_id',
        'submitted_by',
        'title',
        'competition',
        'award_date',
        'status',
        'notes',
    ];
}
