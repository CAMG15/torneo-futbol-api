<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamPayment extends Model
{
    use BelongsToTenant;

    protected $table = 'team_payments';

    protected $fillable = [
        'tenant_id',
        'team_id',
        'tournament_id',
        'concepto',
        'descripcion',
        'monto',
        'monto_pagado',
        'estado',
        'metodo_pago',
        'fecha_vencimiento',
        'pagado_at',
        'notas',
        'created_by',
    ];

    protected $casts = [
        'monto'             => 'decimal:2',
        'monto_pagado'      => 'decimal:2',
        'fecha_vencimiento' => 'date',
        'pagado_at'         => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function tournament(): BelongsTo
    {
        return $this->belongsTo(Tournament::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getMontoPendienteAttribute(): float
    {
        return max(0, $this->monto - $this->monto_pagado);
    }

    public function getEsVencidoAttribute(): bool
    {
        return $this->fecha_vencimiento
            && $this->fecha_vencimiento->isPast()
            && $this->estado !== 'pagado'
            && $this->estado !== 'cancelado';
    }

    protected static function booted(): void
    {
        static::saving(function (TeamPayment $payment) {
            if ($payment->monto_pagado >= $payment->monto) {
                $payment->estado = 'pagado';
                if (!$payment->pagado_at) {
                    $payment->pagado_at = now();
                }
            } elseif ($payment->monto_pagado > 0) {
                $payment->estado = 'parcial';
            }
        });
    }
}
