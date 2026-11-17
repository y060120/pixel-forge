<?php

namespace App\Models;

use App\Services\PixelforgePricing;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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

    /**
     * Apply status, results, usage, and billing from Pixelforge payload data.
     *
     * @param  array<string, mixed>  $genData
     */
    public function applyPixelforgeData(array $genData, ?float $markupPercent = null): void
    {
        $markupPercent = $markupPercent ?? (float) config('services.pixelforge.markup_percent', 25.0);
        $status = $genData['status'] ?? null;

        if ($status === 'processing' && $this->canTransitionTo('processing')) {
            $this->status = 'processing';
            $this->started_at = isset($genData['started_at'])
                ? Carbon::parse($genData['started_at'])
                : now();
            $this->save();
        } elseif ($status === 'succeeded') {
            if ($this->isFinal() && $this->status !== 'succeeded') {
                return;
            }

            $usage = $genData['usage'] ?? [];
            $rates = $genData['rates'] ?? PixelforgePricing::DEFAULT_RATES;
            $calculated = PixelforgePricing::calculateActualCost($usage, $rates, $markupPercent);

            $this->status = 'succeeded';
            $this->image_url = $genData['image_url'] ?? null;
            $this->image_url_expires_at = isset($genData['image_url_expires_at'])
                ? Carbon::parse($genData['image_url_expires_at'])
                : null;
            $this->usage = $usage;
            $this->rates = $rates;
            $this->pf_cost = $calculated['pf_cost'];
            $this->user_cost = $calculated['user_cost'];
            $this->completed_at = isset($genData['completed_at'])
                ? Carbon::parse($genData['completed_at'])
                : now();
            $this->error_code = null;
            $this->error_message = null;
            $this->save();

            // Deduct credits from user if not charged yet
            $alreadyCharged = $this->user->creditTransactions()
                ->where('reference_id', (string) $this->id)
                ->where('type', 'generation_charge')
                ->exists();

            if (! $alreadyCharged && bccomp((string) $this->user_cost, '0', 9) > 0) {
                $this->user->deductCredits(
                    $this->user_cost,
                    (string) $this->id,
                    'Image generation: '.Str::limit($this->prompt, 40)
                );
            }
        } elseif ($status === 'failed') {
            if ($this->isFinal()) {
                return;
            }

            $error = $genData['error'] ?? [];
            $this->status = 'failed';
            $this->error_code = $error['code'] ?? 'generation_failed';
            $this->error_message = $error['message'] ?? 'Image generation failed';
            $this->completed_at = isset($genData['completed_at'])
                ? Carbon::parse($genData['completed_at'])
                : now();
            $this->pf_cost = '0.000000000';
            $this->user_cost = '0.000000000';
            $this->save();
        }
    }
}
