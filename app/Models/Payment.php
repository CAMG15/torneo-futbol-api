<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'subscription_id',
        'amount_cents',
        'currency',
        'payment_provider',
        'provider_payment_id',
        'status',
        'payment_method',
        'description',
        'provider_data',
        'paid_at',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'provider_data' => 'array',
        'paid_at' => 'datetime',
    ];

    // --- Relationships ---

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    // --- Helpers ---

    public function getAmountAttribute(): float
    {
        return $this->amount_cents / 100;
    }

    public function getFormattedAmountAttribute(): string
    {
        return '$' . number_format($this->amount, 2) . ' ' . $this->currency;
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    // --- Scopes ---

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
