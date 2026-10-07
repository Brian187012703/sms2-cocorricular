<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BudgetRequest extends Model
{
    use HasFactory;

    protected $table = 'budget_requests';

    protected $fillable = [
        'club_id',
        'title',
        'description',
        'line_items',
        'amount',
        'recommended_amount',
        'final_approved_amount',
        'status',
        'requested_by',
        'notes',
        'disbursed_at',
        'disbursement_reference',
        'disbursed_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'recommended_amount' => 'decimal:2',
        'final_approved_amount' => 'decimal:2',
        'disbursed_at' => 'datetime',
    ];

    public function club()
    {
        return $this->belongsTo(Club::class, 'club_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function disburser()
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }
}
