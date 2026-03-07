<?php

namespace App\Models;

use App\Mail\PasswordResetMail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_superadmin',
        'current_tenant_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_superadmin' => 'boolean',
    ];

    // ========== PASSWORD RESET ==========

    public function sendPasswordResetNotification($token): void
    {
        $url = config('app.frontend_url') . '/auth/reset-password?token=' . $token . '&email=' . urlencode($this->email);
        Mail::to($this->email)->send(new PasswordResetMail($this, $url));
    }

    // ========== TENANT RELATIONSHIPS ==========

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function ownedTenants(): HasMany
    {
        return $this->hasMany(Tenant::class, 'owner_id');
    }

    public function currentTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'current_tenant_id');
    }

    // ========== HELPERS ==========

    public function roleInTenant(Tenant $tenant): ?string
    {
        $pivot = $this->tenants()->where('tenant_id', $tenant->id)->first();
        return $pivot?->pivot?->role;
    }

    public function isSuperAdmin(): bool
    {
        return $this->is_superadmin ?? false;
    }

    public function belongsToTenant(int $tenantId): bool
    {
        return $this->tenants()->where('tenant_id', $tenantId)->exists();
    }

    public function switchTenant(int $tenantId): void
    {
        if ($this->belongsToTenant($tenantId)) {
            $this->update(['current_tenant_id' => $tenantId]);
        }
    }
}
