<?php
// app/Models/Player.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Player extends Model
{
    protected $fillable = [
        'name', 'lastname', 'nickname', 'birthdate', 'photo',
        'position', 'phone', 'email', 'goals', 'assists',
        'yellow_cards', 'red_cards', 'matches_played', 'active'
    ];

    protected $casts = [
        'birthdate' => 'date',
        'active' => 'boolean'
    ];

    protected $appends = ['full_name', 'age'];

    public function getFullNameAttribute()
    {
        return "{$this->name} {$this->lastname}";
    }

    public function getAgeAttribute()
    {
        return $this->birthdate->age;
    }

    public function teams()
    {
        return $this->belongsToMany(Team::class, 'player_team')
            ->withPivot('jersey_number', 'joined_at', 'left_at')
            ->withTimestamps();
    }

    public function currentTeams()
    {
        return $this->teams()->whereNull('player_team.left_at');
    }

    public function matchEvents()
    {
        return $this->hasMany(MatchEvent::class);
    }
}