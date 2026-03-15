<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TournamentSlotReservation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'tournament_id',
        'team_id',
        'preferred_time',
        'preferred_day_of_week',
        'cancha_id',
        'monto',
        'estado_pago',
        'metodo_pago',
        'notas',
    ];

    protected $casts = [
        'preferred_day_of_week' => 'integer',
        'cancha_id'             => 'integer',
        'monto'                 => 'decimal:2',
    ];

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function cancha(): BelongsTo
    {
        return $this->belongsTo(Cancha::class);
    }

    /**
     * Determina si este horario fijo aplica para un slot dado.
     * En multi_day, verifica también el día; en single_day solo la hora.
     */
    public function matchesSlot(string $slotTime, ?int $slotDayOfWeek = null): bool
    {
        $timeMatch = substr($this->preferred_time, 0, 5) === substr($slotTime, 0, 5);

        if (!$timeMatch) {
            return false;
        }

        // Si la reserva no especifica día, coincide con cualquier slot de esa hora
        if ($this->preferred_day_of_week === null) {
            return true;
        }

        // Si la reserva especifica día, debe coincidir con el día del slot
        return $slotDayOfWeek === null || $this->preferred_day_of_week === $slotDayOfWeek;
    }
}
