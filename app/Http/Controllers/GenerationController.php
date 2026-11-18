<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\User;
use App\Services\PixelforgeClient;
use App\Services\PixelforgePricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    /**
     * Display the generation studio and user's generation history.
     */
    public function index(Request $request, PixelforgeClient $client): Response
    {
        $user = $request->user();

        $generations = Generation::where('user_id', $user->id)
            ->latest('id')
            ->paginate(15)
            ->through(fn ($gen) => [
                'id' => $gen->id,
                'pf_generation_id' => $gen->pf_generation_id,
                'prompt' => $gen->prompt,
                'size' => $gen->size,
                'quality' => $gen->quality,
                'reference_images' => $gen->reference_images ?? [],
                'status' => $gen->status,
                'image_url' => $gen->image_url,
                'image_url_expires_at' => $gen->image_url_expires_at?->toIso8601String(),
                'is_expired' => $gen->image_url_expires_at ? $gen->image_url_expires_at->isPast() : false,
                'estimated_cost' => $gen->estimated_cost,
                'pf_cost' => $gen->pf_cost,
                'user_cost' => $gen->user_cost,
                'usage' => $gen->usage,
                'rates' => $gen->rates,
                'error_code' => $gen->error_code,
                'error_message' => $gen->error_message,
                'started_at' => $gen->started_at?->diffForHumans(),
                'completed_at' => $gen->completed_at?->diffForHumans(),
                'created_at' => $gen->created_at->diffForHumans(),
                'created_at_raw' => $gen->created_at->toIso8601String(),
            ]);

        $activeCount = Generation::where('user_id', $user->id)
            ->whereIn('status', ['queued', 'processing'])
            ->count();

        $pricingConfig = $client->getPricing();

        return Inertia::render('Generations/Index', [
            'generations' => $generations,
            'activeCount' => $activeCount,
            'pricingConfig' => $pricingConfig,
            'markupPercent' => (float) config('services.pixelforge.markup_percent', 25.0),
        ]);
    }

    /**
     * Live cost estimation endpoint for the interactive UI form.
     */
    public function estimate(Request $request, PixelforgeClient $client): JsonResponse
    {
        $prompt = (string) $request->input('prompt', '');
        $size = (string) $request->input('size', '1024x1024');
        $quality = (string) $request->input('quality', 'medium');
        $refImages = (array) $request->input('reference_images', []);
        $refCount = count(array_filter($refImages));

        $pricing = $client->getPricing();
        $rates = $pricing['rates'] ?? PixelforgePricing::DEFAULT_RATES;

        $estimate = PixelforgePricing::estimateCost(
            $prompt,
            $size,
            $quality,
            $refCount,
            $rates,
            (float) config('services.pixelforge.markup_percent', 25.0)
        );

        return response()->json($estimate);
    }

    /**
     * Submit a new generation request.
     * Atomically reserves a credit hold under user row lock before submission.
     */
    public function store(Request $request, PixelforgeClient $client): RedirectResponse
    {
        $validated = $request->validate([
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['nullable', 'string', 'in:1024x1024,1536x1024,1024x1536'],
            'quality' => ['nullable', 'string', 'in:low,medium,high'],
            'reference_images' => ['nullable', 'array', 'max:4'],
            'reference_images.*' => ['nullable', 'url'],
        ]);

        $user = $request->user();
        $size = $validated['size'] ?? '1024x1024';
        $quality = $validated['quality'] ?? 'medium';
        $refImages = array_values(array_filter($validated['reference_images'] ?? []));

        // Upfront pricing estimate using live provider rates
        $pricing = $client->getPricing();
        $rates = $pricing['rates'] ?? PixelforgePricing::DEFAULT_RATES;
        $markupPercent = (float) config('services.pixelforge.markup_percent', 25.0);

        $estimate = PixelforgePricing::estimateCost(
            $validated['prompt'],
            $size,
            $quality,
            count($refImages),
            $rates,
            $markupPercent
        );

        $maxRequired = $estimate['user_cost']['max'];

        // Reserve hold atomically under row lock to prevent overdraft race conditions
        $generation = DB::transaction(function () use ($user, $validated, $size, $quality, $refImages, $estimate, $maxRequired) {
            /** @var User|null $lockedUser */
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->first();

            if (! $lockedUser || ! $lockedUser->hasSufficientBalance($maxRequired)) {
                return null;
            }

            $lockedUser->reserveCredits($maxRequired);

            $idempotencyKey = (string) Str::uuid();

            return Generation::create([
                'user_id' => $lockedUser->id,
                'idempotency_key' => $idempotencyKey,
                'prompt' => $validated['prompt'],
                'size' => $size,
                'quality' => $quality,
                'reference_images' => $refImages,
                'metadata' => [
                    'user_id' => (string) $lockedUser->id,
                ],
                'status' => 'queued',
                'estimated_cost' => $estimate['user_cost']['expected'],
                'hold_amount' => $maxRequired,
                'rates' => $estimate['rates'],
            ]);
        });

        if (! $generation) {
            $freshUser = $user->fresh();

            return back()->withErrors([
                'balance' => sprintf(
                    'Insufficient credits balance. This generation could cost up to $%s, but your available balance is $%s. Please top up your balance first.',
                    number_format((float) $maxRequired, 4),
                    number_format((float) ($freshUser ? $freshUser->availableBalance() : 0), 4)
                ),
            ]);
        }

        // Put local_id in metadata so early webhooks can correlate
        $params = [
            'prompt' => $validated['prompt'],
            'size' => $size,
            'quality' => $quality,
            'reference_images' => $refImages,
            'metadata' => [
                'user_id' => (string) $user->id,
                'local_id' => (string) $generation->id,
            ],
        ];

        // Call Pixelforge API
        $result = $client->createGeneration($params, $generation->idempotency_key);

        if ($result['success']) {
            $pfData = $result['data'];
            $generation->update([
                'pf_generation_id' => $pfData['id'] ?? null,
                'status' => $pfData['status'] ?? 'queued',
                'rates' => $pfData['rates'] ?? $generation->rates,
            ]);

            return redirect()->route('generations.index')->with('success', 'Image generation requested successfully.');
        }

        // Handle Pixelforge rejection or connection error: mark failed and release hold
        $err = $result['error'] ?? [];
        $code = $err['code'] ?? 'unknown_error';
        $message = $err['message'] ?? 'Failed to submit generation to Pixelforge.';

        DB::transaction(function () use ($generation, $user, $maxRequired, $code, $message) {
            $gen = Generation::whereKey($generation->id)->lockForUpdate()->first();
            if ($gen && ! $gen->isFinal()) {
                $gen->update([
                    'status' => 'failed',
                    'error_code' => $code,
                    'error_message' => $message,
                    'pf_cost' => '0.000000000',
                    'user_cost' => '0.000000000',
                    'completed_at' => now(),
                ]);

                /** @var User|null $u */
                $u = User::whereKey($user->id)->lockForUpdate()->first();
                $u?->releaseCredits($maxRequired);
            }
        });

        return redirect()->route('generations.index')->with('error', "Pixelforge error: {$message}");
    }

    /**
     * Poll active generations for real-time UI updates.
     * Reads directly from database to prevent external rate-limit exhaustion.
     */
    public function poll(Request $request): JsonResponse
    {
        $user = $request->user();

        $activeGenerations = Generation::where('user_id', $user->id)
            ->whereIn('status', ['queued', 'processing'])
            ->get();

        // Also fetch recently updated completed generations (last 2 minutes)
        $recentlyFinished = Generation::where('user_id', $user->id)
            ->whereIn('status', ['succeeded', 'failed'])
            ->where('updated_at', '>=', now()->subMinutes(2))
            ->get();

        $allGenerations = $activeGenerations->concat($recentlyFinished)->unique('id')->values();
        $freshUser = $user->fresh();

        return response()->json([
            'generations' => $allGenerations->map(fn ($gen) => [
                'id' => $gen->id,
                'pf_generation_id' => $gen->pf_generation_id,
                'status' => $gen->status,
                'image_url' => $gen->image_url,
                'image_url_expires_at' => $gen->image_url_expires_at?->toIso8601String(),
                'is_expired' => $gen->image_url_expires_at ? $gen->image_url_expires_at->isPast() : false,
                'estimated_cost' => $gen->estimated_cost,
                'pf_cost' => $gen->pf_cost,
                'user_cost' => $gen->user_cost,
                'usage' => $gen->usage,
                'error_code' => $gen->error_code,
                'error_message' => $gen->error_message,
                'completed_at' => $gen->completed_at?->diffForHumans(),
                'updated_at' => $gen->updated_at->toIso8601String(),
            ]),
            'active_count' => $activeGenerations->count(),
            'user_balance' => $freshUser ? $freshUser->balance : '0.000000000',
            'available_balance' => $freshUser ? $freshUser->availableBalance() : '0.000000000',
        ]);
    }
}
