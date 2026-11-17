<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PixelforgeWebhookController extends Controller
{
    /**
     * Handle incoming Pixelforge webhooks.
     */
    public function __invoke(Request $request): Response
    {
        $payload = $request->getContent();
        $secret = config('services.pixelforge.webhook_secret');

        // Verify webhook signature if secret is configured
        if (! empty($secret)) {
            $sigHeader = (string) $request->header('Pixelforge-Signature');
            $signature = [];

            foreach (explode(',', $sigHeader) as $part) {
                [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
                if ($key !== '') {
                    $signature[$key] = $value;
                }
            }

            $timestamp = (int) ($signature['t'] ?? 0);
            $providedSignature = $signature['v1'] ?? '';

            if (empty($timestamp) || empty($providedSignature)) {
                abort(400, 'Missing Pixelforge signature parameters.');
            }

            if (abs(time() - $timestamp) > 300) {
                abort(400, 'Pixelforge signature timestamp out of tolerance.');
            }

            $expectedSignature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

            if (! hash_equals($expectedSignature, $providedSignature)) {
                abort(400, 'Invalid Pixelforge signature.');
            }
        }

        $event = json_decode($payload, true);
        if (! is_array($event)) {
            abort(400, 'Invalid JSON payload.');
        }

        $eventId = $event['id'] ?? $request->header('Pixelforge-Event-Id');
        if (empty($eventId)) {
            abort(400, 'Missing event id.');
        }

        $eventType = $event['type'] ?? $request->header('Pixelforge-Event-Type', 'unknown');
        $deliveryAttempt = (int) $request->header('Pixelforge-Delivery-Attempt', 1);

        // Deduplication & event processing within a transaction
        return DB::transaction(function () use ($eventId, $eventType, $deliveryAttempt, $event) {
            // Check if this event ID was already processed
            if (WebhookEvent::where('event_id', $eventId)->exists()) {
                Log::info("Duplicate Pixelforge webhook received and ignored: {$eventId}");

                return response()->noContent();
            }

            $genData = $event['data']['object'] ?? [];
            $pfGenId = $genData['id'] ?? null;

            if ($pfGenId) {
                $this->processGenerationEvent($eventType, $genData);
            }

            // Record event to prevent duplicate execution
            WebhookEvent::create([
                'event_id' => $eventId,
                'event_type' => $eventType,
                'pf_generation_id' => $pfGenId,
                'payload' => $event,
                'delivery_attempt' => $deliveryAttempt,
                'processed_at' => now(),
            ]);

            return response()->noContent();
        });
    }

    /**
     * Process generation status changes from webhooks.
     */
    protected function processGenerationEvent(string $eventType, array $genData): void
    {
        $pfGenId = $genData['id'];
        $metadata = $genData['metadata'] ?? [];
        $localId = $metadata['local_id'] ?? null;

        // Find existing generation by Pixelforge ID or local ID
        $generation = null;
        if ($pfGenId) {
            $generation = Generation::where('pf_generation_id', $pfGenId)->first();
        }

        if (! $generation && $localId) {
            $generation = Generation::where('id', $localId)->first();
        }

        // If generation doesn't exist locally yet (webhook beat POST response), create it from metadata
        if (! $generation) {
            $userId = $metadata['user_id'] ?? null;
            if ($userId && User::where('id', $userId)->exists()) {
                $generation = Generation::create([
                    'user_id' => $userId,
                    'pf_generation_id' => $pfGenId,
                    'prompt' => $genData['prompt'] ?? 'Untitled',
                    'size' => $genData['size'] ?? '1024x1024',
                    'quality' => $genData['quality'] ?? 'medium',
                    'reference_images' => $genData['reference_images'] ?? [],
                    'metadata' => $metadata,
                    'status' => 'queued',
                ]);
            } else {
                Log::warning("Webhook received for unknown generation {$pfGenId} without valid user_id metadata.");

                return;
            }
        }

        // Always link pf_generation_id if missing
        if (! $generation->pf_generation_id && $pfGenId) {
            $generation->pf_generation_id = $pfGenId;
        }

        // Map webhook event type to generation status if not explicitly present
        if (! isset($genData['status'])) {
            $genData['status'] = match ($eventType) {
                'generation.processing' => 'processing',
                'generation.succeeded' => 'succeeded',
                'generation.failed' => 'failed',
                default => 'queued',
            };
        }

        $generation->applyPixelforgeData($genData);
    }
}
