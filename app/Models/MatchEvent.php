<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MatchEvent extends Model
{
    protected $fillable = [
        'match_id', 'player_id', 'team_id',
        'event_type', 'minute', 'notes'
    ];

    public function match()
    {
        return $this->belongsTo(Matchs::class);
    }

    public function player()
    {
        return $this->belongsTo(Player::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}