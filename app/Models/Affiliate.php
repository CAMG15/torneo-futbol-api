<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Affiliate extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'code',
        'status',
        'commission_rate',
        'total_earned_cents',
        'pending_payout_cents',
        'total_paid_cents',
        'payment_info',
        'notes',
        'approved_at',
    ];

    protected $casts = [
        'commission_rate'      => 'decimal:2',
        'total_earned_cents'   => 'integer',
        'pending_payout_cents' => 'integer',
        'total_paid_cents'     => 'integer',
        'payment_info'         => 'string',
        'approved_at'          => 'datetime',
    ];

    // --- Relationships ---

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(AffiliateReferral::class);
    }

    // --- Scopes ---

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    // --- Helpers ---

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function getTotalEarnedAttribute(): float
    {
        return $this->total_earned_cents / 100;
    }

    public function getPendingPayoutAttribute(): float
    {
        return $this->pending_payout_cents / 100;
    }

    public function getTotalPaidAttribute(): float
    {
        return $this->total_paid_cents / 100;
    }
}
