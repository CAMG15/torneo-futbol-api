<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateReferral extends Model
{
    use HasFactory;

    protected $fillable = [
        'affiliate_id',
        'tenant_id',
        'payment_id',
        'subscription_payment_cents',
        'commission_cents',
        'status',
        'paid_at',
    ];

    protected $casts = [
        'subscription_payment_cents' => 'integer',
        'commission_cents'           => 'integer',
        'paid_at'                    => 'datetime',
    ];

    // --- Relationships ---

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    // --- Helpers ---

    public function getCommissionAttribute(): float
    {
        return $this->commission_cents / 100;
    }

    public function getSubscriptionPaymentAttribute(): float
    {
        return $this->subscription_payment_cents / 100;
    }
}
