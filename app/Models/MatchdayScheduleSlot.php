<?php
// app/Models/MatchdayScheduleSlot.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchdayScheduleSlot extends Model
{
    protected $fillable = [
        'matchday_schedule_id',
        'day_of_week',
        'start_time',
        'location',
        'max_matches',
        'slot_order'
    ];

    protected $casts = [
        'day_of_week' => 'integer',
        'max_matches' => 'integer',
        'slot_order' => 'integer'
    ];

    /**
     * Días de la semana en español
     */
    public const DAYS_OF_WEEK = [
        0 => 'Domingo',
        1 => 'Lunes',
        2 => 'Martes',
        3 => 'Miércoles',
        4 => 'Jueves',
        5 => 'Viernes',
        6 => 'Sábado'
    ];

    /**
     * Relación con el schedule
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(MatchdaySchedule::class, 'matchday_schedule_id');
    }

    /**
     * Obtener el nombre del día de la semana
     */
    public function getDayNameAttribute(): ?string
    {
        return $this->day_of_week !== null
            ? self::DAYS_OF_WEEK[$this->day_of_week]
            : null;
    }

    /**
     * Obtener la hora formateada
     */
    public function getFormattedTimeAttribute(): string
    {
        return date('H:i', strtotime($this->start_time));
    }

    /**
     * Calcular la fecha real para una fecha base
     * @param \Carbon\Carbon $baseDate Fecha de inicio de la jornada
     * @return \Carbon\Carbon
     */
    public function calculateDate($baseDate)
    {
        if ($this->day_of_week === null) {
            return $baseDate->copy();
        }

        $date = $baseDate->copy();
        $baseDayOfWeek = $date->dayOfWeek;
        $targetDayOfWeek = $this->day_of_week;

        if ($targetDayOfWeek >= $baseDayOfWeek) {
            $date->addDays($targetDayOfWeek - $baseDayOfWeek);
        } else {
            $date->addDays(7 - $baseDayOfWeek + $targetDayOfWeek);
        }

        return $date;
    }
}
