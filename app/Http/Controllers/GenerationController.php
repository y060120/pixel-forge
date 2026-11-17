<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Services\PixelforgeClient;
use App\Services\PixelforgePricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function estimate(Request $request): JsonResponse
    {
        $prompt = (string) $request->input('prompt', '');
        $size = (string) $request->input('size', '1024x1024');
        $quality = (string) $request->input('quality', 'medium');
        $refImages = (array) $request->input('reference_images', []);
        $refCount = count(array_filter($refImages));

        $estimate = PixelforgePricing::estimateCost(
            $prompt,
            $size,
            $quality,
            $refCount,
            null,
            (float) config('services.pixelforge.markup_percent', 25.0)
        );

        return response()->json($estimate);
    }

    /**
     * Submit a new generation request.
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

        // Upfront pricing estimate
        $markupPercent = (float) config('services.pixelforge.markup_percent', 25.0);
        $estimate = PixelforgePricing::estimateCost(
            $validated['prompt'],
            $size,
            $quality,
            count($refImages),
            null,
            $markupPercent
        );

        $maxRequired = $estimate['user_cost']['max'];

        // Balance validation: block generation if user cannot afford max potential cost
        if (! $user->hasSufficientBalance($maxRequired)) {
            return back()->withErrors([
                'balance' => sprintf(
                    'Insufficient credits balance. This generation could cost up to $%s, but your balance is $%s. Please top up your balance first.',
                    number_format((float) $maxRequired, 4),
                    number_format((float) $user->balance, 4)
                ),
            ]);
        }

        $idempotencyKey = (string) Str::uuid();

        // Create local pending record
        $generation = Generation::create([
            'user_id' => $user->id,
            'idempotency_key' => $idempotencyKey,
            'prompt' => $validated['prompt'],
            'size' => $size,
            'quality' => $quality,
            'reference_images' => $refImages,
            'metadata' => [
                'user_id' => (string) $user->id,
            ],
            'status' => 'queued',
            'estimated_cost' => $estimate['user_cost']['expected'],
            'rates' => $estimate['rates'],
        ]);

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
        $result = $client->createGeneration($params, $idempotencyKey);

        if ($result['success']) {
            $pfData = $result['data'];
            $generation->update([
                'pf_generation_id' => $pfData['id'] ?? null,
                'status' => $pfData['status'] ?? 'queued',
                'rates' => $pfData['rates'] ?? $generation->rates,
            ]);

            return redirect()->route('generations.index')->with('success', 'Image generation requested successfully.');
        }

        // Handle Pixelforge rejection/errors
        $err = $result['error'] ?? [];
        $code = $err['code'] ?? 'unknown_error';
        $message = $err['message'] ?? 'Failed to submit generation to Pixelforge.';

        $generation->update([
            'status' => 'failed',
            'error_code' => $code,
            'error_message' => $message,
            'pf_cost' => '0.000000000',
            'user_cost' => '0.000000000',
            'completed_at' => now(),
        ]);

        return redirect()->route('generations.index')->with('error', "Pixelforge error: {$message}");
    }

    /**
     * Poll active generations for real-time UI updates.
     */
    public function poll(Request $request, PixelforgeClient $client): JsonResponse
    {
        $user = $request->user();

        $activeGenerations = Generation::where('user_id', $user->id)
            ->whereIn('status', ['queued', 'processing'])
            ->get();

        // Fallback sync for localhost or delayed webhooks: query Pixelforge API directly
        foreach ($activeGenerations as $gen) {
            if ($gen->pf_generation_id) {
                $remote = $client->getGeneration($gen->pf_generation_id);
                if (! empty($remote['success']) && ! empty($remote['data'])) {
                    $remoteData = $remote['data'];
                    if (in_array($remoteData['status'] ?? '', ['succeeded', 'failed'], true)) {
                        $gen->applyPixelforgeData($remoteData);
                    }
                }
            }
        }

        // Re-query active generations after fallback sync
        $activeGenerations = Generation::where('user_id', $user->id)
            ->whereIn('status', ['queued', 'processing'])
            ->get();

        // Also fetch recently updated completed generations (last 2 minutes)
        $recentlyFinished = Generation::where('user_id', $user->id)
            ->whereIn('status', ['succeeded', 'failed'])
            ->where('updated_at', '>=', now()->subMinutes(2))
            ->get();

        $allGenerations = $activeGenerations->concat($recentlyFinished)->unique('id')->values();

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
            'user_balance' => $user->fresh()->balance,
        ]);
    }
}
