<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class TenantDomain extends Model
{
    protected $fillable = [
        'tenant_id',
        'domain',
        'status',
        'verification_token',
        'verified_at',
        'last_check_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'last_check_at' => 'datetime',
    ];

    // ========== RELATIONSHIPS ==========

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    // ========== HELPERS ==========

    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function markAsVerified(): void
    {
        $this->update([
            'status' => 'verified',
            'verified_at' => now(),
            'last_check_at' => now(),
        ]);
    }

    public function markAsFailed(): void
    {
        $this->update([
            'status' => 'failed',
            'last_check_at' => now(),
        ]);
    }

    public static function generateVerificationToken(): string
    {
        return 'micopa-verify=' . Str::random(32);
    }

    public function getDnsInstructionAttribute(): string
    {
        return "Agrega un registro TXT en tu dominio: {$this->verification_token}";
    }
}
