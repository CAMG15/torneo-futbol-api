<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name', 'logo', 'primary_color', 'secondary_color',
        'captain_id', 'matches_played', 'wins', 'draws',
        'losses', 'goals_for', 'goals_against', 'points', 'active'
    ];

    protected $casts = ['active' => 'boolean'];

    protected $appends = ['goal_difference'];

    public function getGoalDifferenceAttribute()
    {
        return $this->goals_for - $this->goals_against;
    }

    public function captain()
    {
        return $this->belongsTo(Player::class, 'captain_id');
    }

    public function players()
    {
        return $this->belongsToMany(Player::class, 'player_team')
            ->withPivot('jersey_number', 'joined_at', 'left_at')
            ->withTimestamps();
    }

    public function currentPlayers()
    {
        return $this->players()->whereNull('player_team.left_at');
    }

    public function homeMatches()
    {
        return $this->hasMany(Matchs::class, 'home_team_id');
    }

    public function awayMatches()
    {
        return $this->hasMany(Matchs::class, 'away_team_id');
    }
}