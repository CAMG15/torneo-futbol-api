<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cancha extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'nombre',
        'tipo',
        'descripcion',
        'activa',
    ];

    protected $casts = [
        'activa' => 'boolean',
    ];

    public function tarifas(): HasMany
    {
        return $this->hasMany(CanchaTarifa::class);
    }

    public function reservas(): HasMany
    {
        return $this->hasMany(Reserva::class);
    }

    public function recurrencias(): HasMany
    {
        return $this->hasMany(ReservaRecurrencia::class);
    }
}
