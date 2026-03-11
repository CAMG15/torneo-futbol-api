<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tenant extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'owner_id',
        'plan',
        'logo',
        'favicon',
        'cover_image',
        'primary_color',
        'secondary_color',
        'font_family',
        'custom_domain',
        'address',
        'city',
        'phone',
        'email',
        'description',
        'is_active',
        'trial_ends_at',
        'settings',
        'referred_by_code',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'trial_ends_at' => 'datetime',
        'settings' => 'array',
    ];

    // ========== RELATIONSHIPS ==========

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function tournaments(): HasMany
    {
        return $this->hasMany(Tournament::class);
    }

    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    public function advertisements(): HasMany
    {
        return $this->hasMany(Advertisement::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class);
    }

    public function verifiedDomain()
    {
        return $this->hasOne(TenantDomain::class)->where('status', 'verified')->latestOfMany();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription()
    {
        return $this->hasOne(Subscription::class)->where('status', 'active')->latestOfMany();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    // ========== PLAN HELPERS ==========

    public function isFreePlan(): bool
    {
        return $this->plan === 'free';
    }

    public function isProPlan(): bool
    {
        return in_array($this->plan, ['pro', 'pro_annual']);
    }

    public function isBusinessPlan(): bool
    {
        return in_array($this->plan, ['business', 'business_annual']);
    }

    public function canCreateTournament(): bool
    {
        if ($this->plan !== 'free') return true;
        return $this->tournaments()->count() < 1;
    }

    public function hasPublicSite(): bool
    {
        return in_array($this->plan, ['free', 'pro', 'pro_annual', 'business', 'business_annual']);
    }

    public function hasCustomDomain(): bool
    {
        return in_array($this->plan, ['business', 'business_annual']) && !empty($this->custom_domain);
    }

    public function showMiCopaBranding(): bool
    {
        return !in_array($this->plan, ['business', 'business_annual']);
    }

    public function canExport(): bool
    {
        return in_array($this->plan, ['pro', 'pro_annual', 'business', 'business_annual']);
    }

    public function hasAdvancedStats(): bool
    {
        return in_array($this->plan, ['business', 'business_annual']);
    }

    public function canShowSponsors(): bool
    {
        return in_array($this->plan, ['business', 'business_annual']);
    }

    public function canCreateReserva(): bool
    {
        if ($this->plan !== 'free') return true;
        return $this->reservasMesActual() < 10;
    }

    public function reservasMesActual(): int
    {
        $mes = now()->format('Y-m');
        return \App\Models\Reserva::where('tenant_id', $this->id)
            ->where('estado', '!=', 'cancelada')
            ->whereRaw("DATE_FORMAT(fecha, '%Y-%m') = ?", [$mes])
            ->count();
    }
}
