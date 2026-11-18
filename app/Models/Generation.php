<?php

namespace App\Models;

use App\Services\PixelforgePricing;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        'hold_amount',
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
            'hold_amount' => 'decimal:9',
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
     * Settle inside a database transaction with pessimistic row locking.
     *
     * @param  array<string, mixed>  $genData
     */
    public function applyPixelforgeData(array $genData, ?float $markupPercent = null): void
    {
        DB::transaction(function () use ($genData, $markupPercent) {
            /** @var Generation|null $gen */
            $gen = self::whereKey($this->id)->lockForUpdate()->first();
            if (! $gen) {
                return;
            }

            $status = $genData['status'] ?? null;
            $markupPercent = $markupPercent ?? (float) config('services.pixelforge.markup_percent', 25.0);

            // Succeeded state is strictly final and cannot be modified or re-charged.
            if ($gen->status === 'succeeded') {
                return;
            }

            // Failed state cannot regress to processing or queued.
            // If Pixelforge actually succeeded (e.g. after a client-side timeout),
            // allow transition to 'succeeded' so the image is delivered and customer is billed.
            if ($gen->status === 'failed' && $status !== 'succeeded') {
                return;
            }

            if ($status === 'processing') {
                if ($gen->canTransitionTo('processing')) {
                    $gen->status = 'processing';
                    $gen->started_at = isset($genData['started_at'])
                        ? Carbon::parse($genData['started_at'])
                        : now();
                    $gen->save();
                    $this->fill($gen->getAttributes());
                }

                return;
            }

            if ($status === 'succeeded') {
                $usage = $genData['usage'] ?? [];
                $rates = $genData['rates'] ?? PixelforgePricing::DEFAULT_RATES;
                $calculated = PixelforgePricing::calculateActualCost($usage, $rates, $markupPercent);

                $gen->status = 'succeeded';
                $gen->image_url = $genData['image_url'] ?? null;
                $gen->image_url_expires_at = isset($genData['image_url_expires_at'])
                    ? Carbon::parse($genData['image_url_expires_at'])
                    : null;
                $gen->usage = $usage;
                $gen->rates = $rates;
                $gen->pf_cost = $calculated['pf_cost'];
                $gen->user_cost = $calculated['user_cost'];
                $gen->completed_at = isset($genData['completed_at'])
                    ? Carbon::parse($genData['completed_at'])
                    : now();
                $gen->error_code = null;
                $gen->error_message = null;
                $gen->save();

                // Settle user hold and charge under user lock
                /** @var User|null $user */
                $user = User::whereKey($gen->user_id)->lockForUpdate()->first();
                if ($user) {
                    $holdAmount = (string) ($gen->hold_amount ?? '0.000000000');
                    if (bccomp($holdAmount, '0', 9) > 0) {
                        $user->releaseCredits($holdAmount);
                    }

                    if (bccomp((string) $gen->user_cost, '0', 9) > 0) {
                        $user->deductCredits(
                            $gen->user_cost,
                            (string) $gen->id,
                            'Image generation: '.Str::limit($gen->prompt, 40)
                        );
                    }
                }

                $this->fill($gen->getAttributes());

                return;
            }

            if ($status === 'failed') {
                $error = $genData['error'] ?? [];
                $gen->status = 'failed';
                $gen->error_code = $error['code'] ?? 'generation_failed';
                $gen->error_message = $error['message'] ?? 'Image generation failed';
                $gen->completed_at = isset($genData['completed_at'])
                    ? Carbon::parse($genData['completed_at'])
                    : now();
                $gen->pf_cost = '0.000000000';
                $gen->user_cost = '0.000000000';
                $gen->save();

                // Release entire hold on failure (zero cost guarantee)
                /** @var User|null $user */
                $user = User::whereKey($gen->user_id)->lockForUpdate()->first();
                if ($user) {
                    $holdAmount = (string) ($gen->hold_amount ?? '0.000000000');
                    if (bccomp($holdAmount, '0', 9) > 0) {
                        $user->releaseCredits($holdAmount);
                    }
                }

                $this->fill($gen->getAttributes());

                return;
            }
        });
    }
}
