<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Generation extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'pf_generation_id',
        'idempotency_key',
        'prompt',
        'size',
        'quality',
        'reference_images',
        'metadata',
        'status',
        'image_url',
        'image_url_expires_at',
        'estimated_cost',
        'pf_cost',
        'user_cost',
        'usage',
        'rates',
        'error_code',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'reference_images' => 'array',
            'metadata' => 'array',
            'usage' => 'array',
            'rates' => 'array',
            'estimated_cost' => 'decimal:9',
            'pf_cost' => 'decimal:9',
            'user_cost' => 'decimal:9',
            'image_url_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Succeeded and failed states are final and cannot be rolled back.
     */
    public function isFinal(): bool
    {
        return in_array($this->status, ['succeeded', 'failed'], true);
    }

    /**
     * Check if a transition to the new status is permitted.
     * Prevents out-of-order events from regressing a final state.
     */
    public function canTransitionTo(string $newStatus): bool
    {
        if ($this->isFinal()) {
            return false;
        }

        if ($this->status === 'processing' && $newStatus === 'queued') {
            return false;
        }

        return true;
    }
}
