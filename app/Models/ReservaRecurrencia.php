<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReservaRecurrencia extends Model
{
    use BelongsToTenant;

    protected $table = 'reserva_recurrencias';

    protected $fillable = [
        'tenant_id',
        'cancha_id',
        'dia_semana',
        'hora_inicio',
        'hora_fin',
        'tipo_evento',
        'nombre_cliente',
        'telefono',
        'nombre_equipo',
        'monto_total',
        'metodo_pago',
        'fecha_inicio',
        'fecha_fin',
        'activa',
    ];

    protected $casts = [
        'dia_semana'  => 'integer',
        'monto_total' => 'decimal:2',
        'fecha_inicio' => 'date',
        'fecha_fin'    => 'date',
        'activa'       => 'boolean',
    ];

    public function cancha(): BelongsTo
    {
        return $this->belongsTo(Cancha::class);
    }

    public function reservas(): HasMany
    {
        return $this->hasMany(Reserva::class, 'recurrencia_id');
    }
}
