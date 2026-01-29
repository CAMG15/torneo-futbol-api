<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class Advertisement extends Model
{
    protected $fillable = [
        'title',
        'image',
        'link',
        'position',
        'order',
        'active',
        'start_date',
        'end_date'
    ];

    protected $casts = [
        'active' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
        'order' => 'integer'
    ];

    /**
     * Scope para anuncios activos
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true)
            ->where(function($q) {
                $q->whereNull('start_date')
                  ->orWhere('start_date', '<=', now());
            })
            ->where(function($q) {
                $q->whereNull('end_date')
                  ->orWhere('end_date', '>=', now());
            });
    }

    /**
     * Scope para una posición específica
     */
    public function scopePosition(Builder $query, string $position): Builder
    {
        return $query->where('position', $position);
    }

    /**
     * Scope para ordenar por prioridad
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('order')->orderBy('created_at', 'desc');
    }

    /**
     * Verificar si el anuncio está vigente
     */
    public function isValid(): bool
    {
        if (!$this->active) {
            return false;
        }

        $now = now();

        if ($this->start_date && $this->start_date > $now) {
            return false;
        }

        if ($this->end_date && $this->end_date < $now) {
            return false;
        }

        return true;
    }

    /**
     * Obtener días restantes de vigencia
     */
    public function getDaysRemainingAttribute(): ?int
    {
        if (!$this->end_date) {
            return null;
        }

        return now()->diffInDays($this->end_date, false);
    }

    /**
     * Verificar si está próximo a expirar (menos de 7 días)
     */
    public function isExpiringSoon(): bool
    {
        if (!$this->end_date) {
            return false;
        }

        $daysRemaining = $this->days_remaining;
        return $daysRemaining !== null && $daysRemaining <= 7 && $daysRemaining >= 0;
    }
}