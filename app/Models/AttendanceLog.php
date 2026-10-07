<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceLog extends Model
{
    use HasFactory;

    protected $table = 'attendance_logs';

    public $timestamps = false;

    protected $fillable = [
        'event_id',
        'user_id',
        'check_in',
        'method',
        'logged_by',
        'override_reason',
        'status',
    ];

    protected $casts = [
        'check_in' => 'datetime',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function logger()
    {
        return $this->belongsTo(User::class, 'logged_by');
    }
}
