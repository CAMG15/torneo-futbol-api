<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $fillable = [
        'tournament_id',
        'team_id',
        'type',
        'title',
        'message',
        'data',
        'sent_at',
        'read'
    ];

    protected $casts = [
        'data' => 'array',
        'sent_at' => 'datetime',
        'read' => 'boolean'
    ];

    public function tournament()
    {
        return $this->belongsTo(Tournament::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}