<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'event_type',
        'pf_generation_id',
        'payload',
        'delivery_attempt',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'delivery_attempt' => 'integer',
            'processed_at' => 'datetime',
        ];
    }
}
