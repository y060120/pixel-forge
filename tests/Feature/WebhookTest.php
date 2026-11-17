<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    protected string $secret = 'whsec_testsecret123456';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.pixelforge.webhook_secret', $this->secret);
        Config::set('services.pixelforge.markup_percent', 25.0);
    }

    protected function createSignedHeaders(string $payload, ?int $timestamp = null): array
    {
        $timestamp = $timestamp ?? time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);

        return [
            'Content-Type' => 'application/json',
            'Pixelforge-Signature' => "t={$timestamp},v1={$signature}",
        ];
    }

    public function test_rejects_invalid_signature(): void
    {
        $payload = json_encode(['id' => 'evt_test1', 'type' => 'ping']);
        $headers = [
            'Content-Type' => 'application/json',
            'Pixelforge-Signature' => 't='.time().',v1=bad_signature_hash',
        ];

        $response = $this->call('POST', '/webhooks/pixelforge', [], [], [], $this->transformHeadersToServerVars($headers), $payload);
        $response->assertStatus(400);
    }

    public function test_rejects_expired_timestamp(): void
    {
        $payload = json_encode(['id' => 'evt_test2', 'type' => 'ping']);
        $oldTime = time() - 305; // 5 minutes and 5 seconds ago
        $headers = $this->createSignedHeaders($payload, $oldTime);

        $response = $this->call('POST', '/webhooks/pixelforge', [], [], [], $this->transformHeadersToServerVars($headers), $payload);
        $response->assertStatus(400);
    }

    public function test_processes_valid_generation_succeeded_event_and_charges_user(): void
    {
        $user = User::factory()->create(['balance' => '10.000000000']);
        $generation = Generation::create([
            'user_id' => $user->id,
            'pf_generation_id' => 'gen_succ_123',
            'prompt' => 'A serene lake at sunrise',
            'size' => '1024x1024',
            'quality' => 'medium',
            'status' => 'processing',
        ]);

        $payloadData = [
            'id' => 'evt_success_1',
            'object' => 'event',
            'type' => 'generation.succeeded',
            'created_at' => now()->toIso8601String(),
            'data' => [
                'object' => [
                    'id' => 'gen_succ_123',
                    'object' => 'generation',
                    'status' => 'succeeded',
                    'prompt' => 'A serene lake at sunrise',
                    'size' => '1024x1024',
                    'quality' => 'medium',
                    'image_url' => 'https://pixelforge.promptedit.dev/images/gen_succ_123.png',
                    'image_url_expires_at' => now()->addHours(24)->toIso8601String(),
                    'usage' => [
                        'input_text_tokens' => 10,
                        'input_image_tokens' => 0,
                        'output_tokens' => 1100,
                        'reasoning_tokens' => 500,
                        'total_tokens' => 1610,
                    ],
                    'rates' => [
                        'input_text_tokens' => '5.00',
                        'input_image_tokens' => '10.00',
                        'output_tokens' => '40.00',
                        'reasoning_tokens' => '12.50',
                    ],
                    'completed_at' => now()->toIso8601String(),
                ],
            ],
        ];

        $payload = json_encode($payloadData);
        $headers = $this->createSignedHeaders($payload);

        $response = $this->call('POST', '/webhooks/pixelforge', [], [], [], $this->transformHeadersToServerVars($headers), $payload);
        $response->assertNoContent();

        $generation->refresh();
        $this->assertSame('succeeded', $generation->status);
        $this->assertNotNull($generation->image_url);

        // Usage check: 10 * 0.000005 (0.00005) + 1100 * 0.00004 (0.044) + 500 * 0.0000125 (0.00625) = 0.050300000
        $this->assertSame('0.050300000', $generation->pf_cost);
        // User cost with 25% markup: 0.050300000 * 1.25 = 0.062875000
        $this->assertSame('0.062875000', $generation->user_cost);

        // User balance should have decreased from 10.000000000 by 0.062875000 = 9.937125000
        $user->refresh();
        $this->assertSame('9.937125000', $user->balance);
    }

    public function test_deduplicates_duplicate_events_and_charges_only_once(): void
    {
        $user = User::factory()->create(['balance' => '10.000000000']);
        $generation = Generation::create([
            'user_id' => $user->id,
            'pf_generation_id' => 'gen_dup_456',
            'prompt' => 'Duplicate delivery test [duplicate]',
            'status' => 'queued',
        ]);

        $payloadData = [
            'id' => 'evt_duplicate_delivery_001',
            'object' => 'event',
            'type' => 'generation.succeeded',
            'created_at' => now()->toIso8601String(),
            'data' => [
                'object' => [
                    'id' => 'gen_dup_456',
                    'status' => 'succeeded',
                    'image_url' => 'https://pixelforge.promptedit.dev/images/gen_dup_456.png',
                    'usage' => [
                        'input_text_tokens' => 20,
                        'input_image_tokens' => 0,
                        'output_tokens' => 1100,
                        'reasoning_tokens' => 400,
                        'total_tokens' => 1520,
                    ],
                    'rates' => [
                        'input_text_tokens' => '5.00',
                        'input_image_tokens' => '10.00',
                        'output_tokens' => '40.00',
                        'reasoning_tokens' => '12.50',
                    ],
                ],
            ],
        ];

        $payload = json_encode($payloadData);

        // First delivery attempt
        $headers1 = $this->createSignedHeaders($payload);
        $res1 = $this->call('POST', '/webhooks/pixelforge', [], [], [], $this->transformHeadersToServerVars($headers1), $payload);
        $res1->assertNoContent();

        $balanceAfterFirst = $user->fresh()->balance;

        // Second duplicate delivery attempt (e.g. [duplicate] directive or network retry)
        $headers2 = $this->createSignedHeaders($payload, time() + 1);
        $res2 = $this->call('POST', '/webhooks/pixelforge', [], [], [], $this->transformHeadersToServerVars($headers2), $payload);
        $res2->assertNoContent();

        // User balance must remain unchanged after duplicate event
        $this->assertSame($balanceAfterFirst, $user->fresh()->balance);

        // Only one transaction and one webhook event should exist
        $this->assertSame(1, $user->creditTransactions()->count());
        $this->assertSame(1, WebhookEvent::where('event_id', 'evt_duplicate_delivery_001')->count());
    }

    public function test_failed_generation_is_free_of_charge(): void
    {
        $user = User::factory()->create(['balance' => '5.000000000']);
        $generation = Generation::create([
            'user_id' => $user->id,
            'pf_generation_id' => 'gen_fail_789',
            'prompt' => 'A prompt that fails [fail]',
            'status' => 'processing',
        ]);

        $payloadData = [
            'id' => 'evt_fail_999',
            'object' => 'event',
            'type' => 'generation.failed',
            'data' => [
                'object' => [
                    'id' => 'gen_fail_789',
                    'status' => 'failed',
                    'error' => [
                        'code' => 'generation_failed',
                        'message' => 'The model could not produce an image for the prompt.',
                    ],
                ],
            ],
        ];

        $payload = json_encode($payloadData);
        $headers = $this->createSignedHeaders($payload);

        $response = $this->call('POST', '/webhooks/pixelforge', [], [], [], $this->transformHeadersToServerVars($headers), $payload);
        $response->assertNoContent();

        $generation->refresh();
        $this->assertSame('failed', $generation->status);
        $this->assertSame('0.000000000', $generation->pf_cost);
        $this->assertSame('0.000000000', $generation->user_cost);
        $this->assertSame('generation_failed', $generation->error_code);

        // User balance must NOT be debited
        $this->assertSame('5.000000000', $user->fresh()->balance);
        $this->assertSame(0, $user->creditTransactions()->count());
    }

    public function test_out_of_order_delivery_does_not_regress_succeeded_status(): void
    {
        $user = User::factory()->create();
        $generation = Generation::create([
            'user_id' => $user->id,
            'pf_generation_id' => 'gen_ooo_001',
            'prompt' => 'Out of order test',
            'status' => 'succeeded',
            'user_cost' => '0.050000000',
        ]);

        // Belated generation.processing arrives after succeeded
        $payloadData = [
            'id' => 'evt_late_processing',
            'type' => 'generation.processing',
            'data' => [
                'object' => [
                    'id' => 'gen_ooo_001',
                    'status' => 'processing',
                ],
            ],
        ];

        $payload = json_encode($payloadData);
        $headers = $this->createSignedHeaders($payload);

        $response = $this->call('POST', '/webhooks/pixelforge', [], [], [], $this->transformHeadersToServerVars($headers), $payload);
        $response->assertNoContent();

        // Must still be succeeded!
        $this->assertSame('succeeded', $generation->fresh()->status);
    }
}
