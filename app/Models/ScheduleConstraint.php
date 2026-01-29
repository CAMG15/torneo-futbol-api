<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleConstraint extends Model
{
    protected $fillable = [
        'tournament_id',
        'team_id',
        'constraint_type',
        'constraint_data',
        'active'
    ];

    protected $casts = [
        'constraint_data' => 'array',
        'active' => 'boolean'
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}