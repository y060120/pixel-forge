<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'balance',
        'reserved_balance',
        'is_admin',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'balance' => 'decimal:9',
            'reserved_balance' => 'decimal:9',
            'is_admin' => 'boolean',
        ];
    }

    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class);
    }

    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    /**
     * Get user's available balance (balance minus reserved holds).
     */
    public function availableBalance(): string
    {
        $reserved = (string) ($this->reserved_balance ?? '0.000000000');

        return bcsub((string) $this->balance, $reserved, 9);
    }

    /**
     * Check if user has at least the required amount of available credits.
     */
    public function hasSufficientBalance(string $requiredAmount): bool
    {
        return bccomp($this->availableBalance(), (string) $requiredAmount, 9) >= 0;
    }

    /**
     * Reserve credits for a pending generation.
     */
    public function reserveCredits(string $amount): void
    {
        DB::transaction(function () use ($amount) {
            $user = self::whereKey($this->id)->lockForUpdate()->first();
            if (! $user) {
                return;
            }

            $currentReserved = (string) ($user->reserved_balance ?? '0.000000000');
            $newReserved = bcadd($currentReserved, (string) $amount, 9);

            $user->update(['reserved_balance' => $newReserved]);
            $this->reserved_balance = $newReserved;
        });
    }

    /**
     * Release reserved credits hold.
     */
    public function releaseCredits(string $amount): void
    {
        DB::transaction(function () use ($amount) {
            $user = self::whereKey($this->id)->lockForUpdate()->first();
            if (! $user) {
                return;
            }

            $currentReserved = (string) ($user->reserved_balance ?? '0.000000000');
            $newReserved = bcsub($currentReserved, (string) $amount, 9);
            if (bccomp($newReserved, '0', 9) < 0) {
                $newReserved = '0.000000000';
            }

            $user->update(['reserved_balance' => $newReserved]);
            $this->reserved_balance = $newReserved;
        });
    }

    /**
     * Add credits to user's balance and record transaction.
     */
    public function addCredits(string $amount, ?string $referenceId = null, string $description = 'Credit purchase'): CreditTransaction
    {
        return DB::transaction(function () use ($amount, $referenceId, $description) {
            $user = self::whereKey($this->id)->lockForUpdate()->first();
            $newBalance = bcadd((string) $user->balance, (string) $amount, 9);

            $user->update(['balance' => $newBalance]);
            $this->balance = $newBalance;

            return $this->creditTransactions()->create([
                'type' => 'purchase',
                'amount' => $amount,
                'balance_after' => $newBalance,
                'reference_id' => $referenceId,
                'description' => $description,
            ]);
        });
    }

    /**
     * Deduct credits from user's balance and record transaction.
     * Prevents duplicate charges for the same reference ID.
     */
    public function deductCredits(string $amount, ?string $referenceId = null, string $description = 'Generation charge'): ?CreditTransaction
    {
        return DB::transaction(function () use ($amount, $referenceId, $description) {
            if ($referenceId) {
                $existing = $this->creditTransactions()
                    ->where('type', 'generation_charge')
                    ->where('reference_id', $referenceId)
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            $user = self::whereKey($this->id)->lockForUpdate()->first();
            $newBalance = bcsub((string) $user->balance, (string) $amount, 9);

            $user->update(['balance' => $newBalance]);
            $this->balance = $newBalance;

            return $this->creditTransactions()->create([
                'type' => 'generation_charge',
                'amount' => '-'.ltrim((string) $amount, '-'),
                'balance_after' => $newBalance,
                'reference_id' => $referenceId,
                'description' => $description,
            ]);
        });
    }
}
