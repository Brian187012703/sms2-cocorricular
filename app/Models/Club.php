<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Club extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'category',
        'description',
        'adviser_name',
        'status',
    ];

    public function memberships()
    {
        return $this->hasMany(ClubMembership::class);
    }

    public function events()
    {
        return $this->hasMany(Event::class);
    }

    public function budgetRequests()
    {
        return $this->hasMany(BudgetRequest::class);
    }

    public function achievements()
    {
        return $this->hasMany(Achievement::class);
    }
}
