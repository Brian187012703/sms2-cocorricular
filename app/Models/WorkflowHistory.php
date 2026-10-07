<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkflowHistory extends Model
{
    use HasFactory;

    protected $table = 'workflow_history';

    public $timestamps = false;

    protected $fillable = [
        'module',
        'record_id',
        'from_status',
        'to_status',
        'action',
        'performed_by',
        'actor_role',
        'remarks',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
