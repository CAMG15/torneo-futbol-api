<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CanchaTarifa extends Model
{
    protected $table = 'cancha_tarifas';

    protected $fillable = [
        'cancha_id',
        'nombre',
        'dia_tipo',
        'hora_desde',
        'hora_hasta',
        'precio_hora',
        'moneda',
        'activa',
    ];

    protected $casts = [
        'precio_hora' => 'decimal:2',
        'activa'      => 'boolean',
    ];

    public function cancha(): BelongsTo
    {
        return $this->belongsTo(Cancha::class);
    }
}
