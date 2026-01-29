<?php
// app/Models/TournamentPhase.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TournamentPhase extends Model
{
    protected $fillable = [
        'tournament_id',
        'phase_type',
        'name',
        'phase_order',
        'status',
        'config'
    ];

    protected $casts = [
        'config' => 'array',
        'phase_order' => 'integer'
    ];

    /**
     * Configuración por defecto de liguilla
     */
    public const DEFAULT_CONFIG = [
        'liguilla_teams' => 8,
        'include_copa' => false,
        'copa_teams' => 8,
        'include_repechaje' => false,
        'format' => 'two_legs',
        'final_format' => 'two_legs',
        'tiebreaker' => 'global_score',
        'higher_seed_home_second' => true,
        'days_between_legs' => 4,
        'days_between_rounds' => 7
    ];

    /**
     * Relación con el torneo
     */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    /**
     * Relación con los brackets de playoff
     */
    public function brackets(): HasMany
    {
        return $this->hasMany(PlayoffBracket::class);
    }

    /**
     * Relación con las jornadas de esta fase
     */
    public function matchdays(): HasMany
    {
        return $this->hasMany(Matchday::class);
    }

    /**
     * Verificar si es fase de playoff
     */
    public function isPlayoff(): bool
    {
        return in_array($this->phase_type, ['liguilla', 'copa', 'repechaje']);
    }

    /**
     * Verificar si es fase regular
     */
    public function isRegular(): bool
    {
        return $this->phase_type === 'regular';
    }

    /**
     * Obtener la configuración con valores por defecto
     */
    public function getFullConfigAttribute(): array
    {
        return array_merge(self::DEFAULT_CONFIG, $this->config ?? []);
    }

    /**
     * Obtener brackets por ronda
     */
    public function getBracketsByRound(): array
    {
        return $this->brackets()
            ->orderBy('round_number')
            ->orderBy('bracket_position')
            ->get()
            ->groupBy('round_number')
            ->toArray();
    }

    /**
     * Obtener el nombre de la ronda por número
     */
    public static function getRoundName(int $roundNumber, int $totalTeams): string
    {
        $rounds = self::calculateRounds($totalTeams);

        $names = [
            1 => 'Dieciseisavos de Final',
            2 => 'Octavos de Final',
            3 => 'Cuartos de Final',
            4 => 'Semifinal',
            5 => 'Final'
        ];

        // Ajustar según el total de equipos
        $startRound = 6 - $rounds;

        return $names[$startRound + $roundNumber - 1] ?? "Ronda $roundNumber";
    }

    /**
     * Calcular número de rondas según equipos
     */
    public static function calculateRounds(int $teams): int
    {
        return (int) ceil(log($teams, 2));
    }

    /**
     * Verificar si la fase está completa
     */
    public function isComplete(): bool
    {
        if (!$this->isPlayoff()) {
            // Para fase regular, verificar si todas las jornadas están completas
            return $this->matchdays()
                ->whereHas('matches', function ($q) {
                    $q->where('status', '!=', 'Finalizado');
                })->count() === 0;
        }

        // Para playoff, verificar si hay un ganador en la final
        $finalBracket = $this->brackets()
            ->orderBy('round_number', 'desc')
            ->first();

        return $finalBracket && $finalBracket->winner_team_id !== null;
    }

    /**
     * Marcar como en progreso
     */
    public function markInProgress(): void
    {
        $this->update(['status' => 'in_progress']);
    }

    /**
     * Marcar como completada
     */
    public function markCompleted(): void
    {
        $this->update(['status' => 'completed']);
    }
}
