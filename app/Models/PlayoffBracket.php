<?php
// app/Models/PlayoffBracket.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlayoffBracket extends Model
{
    protected $fillable = [
        'tournament_phase_id',
        'round_number',
        'round_name',
        'bracket_position',
        'home_team_id',
        'away_team_id',
        'home_seed',
        'away_seed',
        'winner_team_id',
        'next_bracket_id',
        'next_bracket_position',
        'home_aggregate_score',
        'away_aggregate_score',
        'is_two_legs'
    ];

    protected $casts = [
        'round_number' => 'integer',
        'bracket_position' => 'integer',
        'home_seed' => 'integer',
        'away_seed' => 'integer',
        'home_aggregate_score' => 'integer',
        'away_aggregate_score' => 'integer',
        'is_two_legs' => 'boolean'
    ];

    /**
     * Relación con la fase del torneo
     */
    public function phase(): BelongsTo
    {
        return $this->belongsTo(TournamentPhase::class, 'tournament_phase_id');
    }

    /**
     * Relación con el equipo local
     */
    public function homeTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    /**
     * Relación con el equipo visitante
     */
    public function awayTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }

    /**
     * Relación con el equipo ganador
     */
    public function winner(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'winner_team_id');
    }

    /**
     * Relación con el siguiente bracket
     */
    public function nextBracket(): BelongsTo
    {
        return $this->belongsTo(PlayoffBracket::class, 'next_bracket_id');
    }

    /**
     * Relación con los partidos de este bracket
     */
    public function matches(): HasMany
    {
        return $this->hasMany(Matchs::class, 'playoff_bracket_id')->orderBy('leg_number');
    }

    /**
     * Obtener partido de ida
     */
    public function getIdaAttribute()
    {
        return $this->matches()->where('leg_number', 1)->first();
    }

    /**
     * Obtener partido de vuelta
     */
    public function getVueltaAttribute()
    {
        return $this->matches()->where('leg_number', 2)->first();
    }

    /**
     * Verificar si ambos equipos están asignados
     */
    public function hasTeams(): bool
    {
        return $this->home_team_id !== null && $this->away_team_id !== null;
    }

    /**
     * Verificar si la serie está completa
     */
    public function isComplete(): bool
    {
        if (!$this->hasTeams()) {
            return false;
        }

        $completedMatches = $this->matches()
            ->where('status', 'Finalizado')
            ->count();

        $requiredMatches = $this->is_two_legs ? 2 : 1;

        return $completedMatches >= $requiredMatches;
    }

    /**
     * Actualizar marcador agregado
     */
    public function updateAggregateScores(): void
    {
        $homeAggregate = 0;
        $awayAggregate = 0;

        foreach ($this->matches as $match) {
            if ($match->status === 'Finalizado') {
                $homeAggregate += $match->home_score ?? 0;
                $awayAggregate += $match->away_score ?? 0;
            }
        }

        $this->update([
            'home_aggregate_score' => $homeAggregate,
            'away_aggregate_score' => $awayAggregate
        ]);
    }

    /**
     * Determinar el ganador según las reglas de desempate
     * @return int|null ID del equipo ganador, o null si hay empate (requiere penales)
     */
    public function determineWinner(): ?int
    {
        if (!$this->isComplete()) {
            return null;
        }

        $config = $this->phase->full_config;
        $homeTotal = $this->home_aggregate_score;
        $awayTotal = $this->away_aggregate_score;

        // Marcador global diferente = hay ganador claro
        if ($homeTotal !== $awayTotal) {
            return $homeTotal > $awayTotal
                ? $this->home_team_id
                : $this->away_team_id;
        }

        // Empate - aplicar regla de desempate
        $tiebreaker = $config['tiebreaker'] ?? 'global_score';

        if ($tiebreaker === 'away_goals' && $this->is_two_legs) {
            // Regla del gol de visitante
            $ida = $this->ida;
            $vuelta = $this->vuelta;

            if ($ida && $vuelta) {
                $homeAwayGoals = $vuelta->away_score ?? 0;
                $awayAwayGoals = $ida->away_score ?? 0;

                if ($homeAwayGoals !== $awayAwayGoals) {
                    return $homeAwayGoals > $awayAwayGoals
                        ? $this->home_team_id
                        : $this->away_team_id;
                }
            }
        }

        if ($tiebreaker === 'higher_seed') {
            // El mejor clasificado en fase regular avanza
            if ($this->home_seed !== null && $this->away_seed !== null) {
                return $this->home_seed < $this->away_seed
                    ? $this->home_team_id
                    : $this->away_team_id;
            }
        }

        // Si sigue empate (global_score, away_goals sin diferencia, o extra_time)
        // Retornar null - admin debe ingresar ganador manual (después de penales)
        return null;
    }

    /**
     * Establecer ganador y avanzar al siguiente bracket
     */
    public function setWinner(int $teamId): void
    {
        $this->update(['winner_team_id' => $teamId]);

        // Avanzar al siguiente bracket si existe
        if ($this->next_bracket_id) {
            $nextBracket = $this->nextBracket;

            if ($this->next_bracket_position === 'home') {
                $nextBracket->update(['home_team_id' => $teamId]);
            } else {
                $nextBracket->update(['away_team_id' => $teamId]);
            }
        }
    }

    /**
     * Verificar si necesita definición por penales
     */
    public function needsPenaltyShootout(): bool
    {
        return $this->isComplete()
            && $this->winner_team_id === null
            && $this->determineWinner() === null;
    }

    /**
     * Obtener descripción del estado de la serie
     */
    public function getSeriesStatusAttribute(): string
    {
        if (!$this->hasTeams()) {
            return 'Esperando equipos';
        }

        if ($this->winner_team_id) {
            return "Ganador: " . ($this->winner->name ?? 'TBD');
        }

        if ($this->isComplete()) {
            if ($this->needsPenaltyShootout()) {
                return 'Definición por penales';
            }
            return 'Serie completa';
        }

        $completedMatches = $this->matches()->where('status', 'Finalizado')->count();
        $totalMatches = $this->is_two_legs ? 2 : 1;

        return "$completedMatches/$totalMatches partidos jugados";
    }

    /**
     * Obtener marcador agregado formateado
     */
    public function getAggregateScoreAttribute(): string
    {
        return "{$this->home_aggregate_score} - {$this->away_aggregate_score}";
    }
}
