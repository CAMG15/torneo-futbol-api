<?php
// app/Models/Matchday.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Matchday extends Model
{
    protected $fillable = [
        'tournament_id',
        'number',
        'name',
        'date',
        'end_date',
        'matchday_schedule_id',
        'phase_type',
        'tournament_phase_id'
    ];

    protected $casts = [
        'date' => 'date',
        'end_date' => 'date'
    ];

    /**
     * Relación con el torneo
     */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    /**
     * Relación con los partidos
     */
    public function matches(): HasMany
    {
        return $this->hasMany(Matchs::class);
    }

    /**
     * Relación con el schedule de horarios
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(MatchdaySchedule::class, 'matchday_schedule_id');
    }

    /**
     * Relación con la fase del torneo
     */
    public function phase(): BelongsTo
    {
        return $this->belongsTo(TournamentPhase::class, 'tournament_phase_id');
    }

    /**
     * Verificar si es jornada de playoff
     */
    public function isPlayoff(): bool
    {
        return $this->phase_type === 'playoff';
    }

    /**
     * Verificar si es jornada de varios días
     */
    public function isMultiDay(): bool
    {
        return $this->end_date !== null && $this->end_date != $this->date;
    }

    /**
     * Obtener el rango de fechas formateado
     */
    public function getDateRangeAttribute(): string
    {
        if (!$this->isMultiDay()) {
            return $this->date->format('d/m/Y');
        }
        return $this->date->format('d/m') . ' - ' . $this->end_date->format('d/m/Y');
    }

    /**
     * Obtener número total de partidos
     */
    public function getTotalMatchesAttribute(): int
    {
        return $this->matches()->count();
    }

    /**
     * Obtener partidos finalizados
     */
    public function getCompletedMatchesAttribute(): int
    {
        return $this->matches()->where('status', 'Finalizado')->count();
    }

    /**
     * Verificar si la jornada está completa
     */
    public function isComplete(): bool
    {
        $totalMatches = $this->matches()->count();
        $completedMatches = $this->matches()->where('status', 'Finalizado')->count();
        
        return $totalMatches > 0 && $totalMatches === $completedMatches;
    }

    /**
     * Obtener el estado de la jornada
     */
    public function getStatusAttribute(): string
    {
        $totalMatches = $this->matches()->count();
        
        if ($totalMatches === 0) {
            return 'Sin Partidos';
        }

        $liveMatches = $this->matches()->where('status', 'En Vivo')->count();
        if ($liveMatches > 0) {
            return 'En Curso';
        }

        $completedMatches = $this->matches()->where('status', 'Finalizado')->count();
        if ($completedMatches === $totalMatches) {
            return 'Finalizado';
        }

        $upcomingMatches = $this->matches()
            ->where('status', 'Programado')
            ->where('match_date', '>', now())
            ->count();
        
        if ($upcomingMatches > 0) {
            return 'Programado';
        }

        return 'Pendiente';
    }

    /**
     * Scope para jornadas de un torneo
     */
    public function scopeOfTournament($query, $tournamentId)
    {
        return $query->where('tournament_id', $tournamentId);
    }

    /**
     * Scope para ordenar por número de jornada
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('number');
    }

    /**
     * Scope para jornadas futuras
     */
    public function scopeUpcoming($query)
    {
        return $query->where('date', '>=', now()->startOfDay());
    }

    /**
     * Scope para jornadas pasadas
     */
    public function scopePast($query)
    {
        return $query->where('date', '<', now()->startOfDay());
    }
}