<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportTicket extends Model
{
    protected $fillable = [
        'tenant_id',
        'user_id',
        'title',
        'description',
        'category',
        'priority',
        'status',
        'screenshots',
        'admin_notes',
        'resolved_at',
    ];

    protected $casts = [
        'screenshots' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
