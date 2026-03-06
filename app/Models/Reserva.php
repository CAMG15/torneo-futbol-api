<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reserva extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'cancha_id',
        'fecha',
        'hora_inicio',
        'hora_fin',
        'tipo_evento',
        'estado',
        'nombre_cliente',
        'telefono',
        'email',
        'nombre_equipo',
        'cantidad_jugadores',
        'monto_total',
        'monto_sena',
        'estado_pago',
        'metodo_pago',
        'notas_cliente',
        'notas_internas',
        'recurrencia_id',
        'created_by',
    ];

    protected $casts = [
        'fecha'       => 'date',
        'monto_total' => 'decimal:2',
        'monto_sena'  => 'decimal:2',
    ];

    public function cancha(): BelongsTo
    {
        return $this->belongsTo(Cancha::class);
    }

    public function recurrencia(): BelongsTo
    {
        return $this->belongsTo(ReservaRecurrencia::class, 'recurrencia_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
