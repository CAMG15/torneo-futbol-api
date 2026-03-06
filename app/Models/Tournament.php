<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Tournament extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'type',
        'start_date',
        'end_date',
        'status',
        'banner'
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date'
    ];

    protected $appends = ['current_matchday', 'progress_percentage'];

    /**
     * Relación con jornadas
     */
    public function matchdays(): HasMany
    {
        return $this->hasMany(Matchday::class);
    }

    /**
     * Relación con partidos a través de jornadas
     */
    public function matches(): HasManyThrough
    {
        return $this->hasManyThrough(Matchs::class, Matchday::class);
    }

    /**
     * Obtener equipos del torneo
     */
    public function teams()
    {
        return $this->belongsToMany(Team::class, 'tournament_team')
            ->withTimestamps();
    }

    /**
     * Relación con las fases del torneo
     */
    public function phases(): HasMany
    {
        return $this->hasMany(TournamentPhase::class)->orderBy('phase_order');
    }

    /**
     * Relación con los schedules de horarios
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(MatchdaySchedule::class);
    }

    /**
     * Obtener la fase regular
     */
    public function getRegularPhaseAttribute()
    {
        return $this->phases()->where('phase_type', 'regular')->first();
    }

    /**
     * Obtener la fase de liguilla
     */
    public function getLiguillaPhaseAttribute()
    {
        return $this->phases()->where('phase_type', 'liguilla')->first();
    }

    /**
     * Obtener la fase de copa
     */
    public function getCopaPhaseAttribute()
    {
        return $this->phases()->where('phase_type', 'copa')->first();
    }

    /**
     * Verificar si tiene liguilla generada
     */
    public function hasLiguilla(): bool
    {
        return $this->phases()->where('phase_type', 'liguilla')->exists();
    }

    /**
     * Verificar si tiene copa generada
     */
    public function hasCopa(): bool
    {
        return $this->phases()->where('phase_type', 'copa')->exists();
    }

    /**
     * Obtener jornada actual
     */
    public function getCurrentMatchdayAttribute()
    {
        return $this->matchdays()
            ->where('date', '<=', now())
            ->orderBy('date', 'desc')
            ->first();
    }

    /**
     * Obtener próxima jornada
     */
    public function getNextMatchdayAttribute()
    {
        return $this->matchdays()
            ->where('date', '>', now())
            ->orderBy('date')
            ->first();
    }

    /**
     * Verificar si el torneo ha iniciado
     */
    public function hasStarted(): bool
    {
        return $this->start_date <= now();
    }

    /**
     * Verificar si el torneo ha finalizado
     */
    public function hasEnded(): bool
    {
        return $this->end_date && $this->end_date <= now();
    }

    /**
     * Obtener porcentaje de progreso del torneo
     */
    public function getProgressPercentageAttribute(): float
    {
        $totalMatchdays = $this->matchdays()->count();
        if ($totalMatchdays === 0) {
            return 0;
        }

        $completedMatchdays = $this->matchdays()
            ->whereHas('matches', function($query) {
                $query->where('status', 'Finalizado');
            })
            ->count();

        return round(($completedMatchdays / $totalMatchdays) * 100, 2);
    }
}