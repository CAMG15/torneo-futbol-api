<?php
// app/Models/MatchdaySchedule.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MatchdaySchedule extends Model
{
    protected $fillable = [
        'tournament_id',
        'schedule_type',
        'name'
    ];

    /**
     * Relación con el torneo
     */
    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    /**
     * Relación con los slots de horarios
     */
    public function slots(): HasMany
    {
        return $this->hasMany(MatchdayScheduleSlot::class)->orderBy('slot_order');
    }

    /**
     * Relación con las jornadas que usan este schedule
     */
    public function matchdays(): HasMany
    {
        return $this->hasMany(Matchday::class);
    }

    /**
     * Verificar si es multi-día
     */
    public function isMultiDay(): bool
    {
        return $this->schedule_type === 'multi_day';
    }

    /**
     * Obtener slots ordenados por día de semana y hora
     */
    public function getSlotsOrderedAttribute()
    {
        return $this->slots()
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->orderBy('slot_order')
            ->get();
    }

    /**
     * Obtener el total de partidos que puede alojar este schedule por jornada
     */
    public function getTotalMatchCapacityAttribute(): int
    {
        return $this->slots()->sum('max_matches');
    }

    /**
     * Obtener días de semana únicos (para multi_day)
     */
    public function getUniqueDaysAttribute(): array
    {
        return $this->slots()
            ->whereNotNull('day_of_week')
            ->distinct()
            ->pluck('day_of_week')
            ->toArray();
    }
}
