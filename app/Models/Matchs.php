<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Matchs extends Model
{
    protected $table = 'matches';   // <-- Aquí declaras la tabla correcta

    protected $fillable = [
        'matchday_id', 'home_team_id', 'away_team_id',
        'match_date', 'location', 'home_score', 'away_score', 'status',
        'playoff_bracket_id', 'leg_number'
    ];

    protected $casts = [
        'match_date' => 'datetime'
    ];

    public function matchday()
    {
        return $this->belongsTo(Matchday::class);
    }

    public function homeTeam()
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    public function awayTeam()
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }

    public function events()
    {
        return $this->hasMany(MatchEvent::class, 'match_id');
    }

    public function goals()
    {
        return $this->events()->where('event_type', 'Gol');
    }

    /**
     * Relación con el bracket de playoff
     */
    public function playoffBracket()
    {
        return $this->belongsTo(PlayoffBracket::class);
    }

    /**
     * Verificar si es partido de playoff
     */
    public function isPlayoff(): bool
    {
        return $this->playoff_bracket_id !== null;
    }

    /**
     * Verificar si es partido de ida
     */
    public function isIda(): bool
    {
        return $this->leg_number === 1;
    }

    /**
     * Verificar si es partido de vuelta
     */
    public function isVuelta(): bool
    {
        return $this->leg_number === 2;
    }

    /**
     * Obtener el nombre del partido de playoff
     */
    public function getPlayoffLabelAttribute(): ?string
    {
        if (!$this->isPlayoff()) {
            return null;
        }

        $bracket = $this->playoffBracket;
        $leg = $this->leg_number === 1 ? 'Ida' : 'Vuelta';

        return "{$bracket->round_name} - {$leg}";
    }
}