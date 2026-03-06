<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'price_cents',
        'currency',
        'billing_period',
        'features',
        'limits',
        'description',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'features' => 'array',
        'limits' => 'array',
        'price_cents' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    // --- Relationships ---

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    // --- Helpers ---

    public function getPriceAttribute(): float
    {
        return $this->price_cents / 100;
    }

    public function getFormattedPriceAttribute(): string
    {
        if ($this->price_cents === 0) {
            return 'Gratis';
        }
        return '$' . number_format($this->price / 100, 2) . ' ' . $this->currency;
    }

    public function hasFeature(string $feature): bool
    {
        return $this->features[$feature] ?? false;
    }

    public function getLimit(string $limit): mixed
    {
        return $this->limits[$limit] ?? null;
    }

    public function isFree(): bool
    {
        return $this->slug === 'free';
    }

    // --- Scopes ---

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
