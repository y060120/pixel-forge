<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GenerationStudioTest extends TestCase
{
    use RefreshDatabase;

    public function test_estimate_endpoint_returns_pricing_breakdown(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson(route('generations.estimate', [
            'prompt' => 'A lighthouse on a cliff at dawn',
            'size' => '1536x1024',
            'quality' => 'high',
        ]));

        $response->assertOk()
            ->assertJsonStructure([
                'tokens' => ['text', 'image', 'output', 'reasoning'],
                'provider_cost' => ['min', 'max', 'expected'],
                'user_cost' => ['min', 'max', 'expected'],
                'markup_percent',
            ]);

        $this->assertEquals(25, $response->json('markup_percent'));
    }

    public function test_blocks_generation_when_user_has_insufficient_balance(): void
    {
        $user = User::factory()->create(['balance' => '0.000000000']);

        $response = $this->actingAs($user)->post(route('generations.store'), [
            'prompt' => 'Generate an expensive high quality image',
            'size' => '1536x1024',
            'quality' => 'high',
        ]);

        $response->assertSessionHasErrors(['balance']);
        $this->assertSame(0, Generation::count());
    }

    public function test_submits_generation_successfully_when_balance_is_sufficient(): void
    {
        $user = User::factory()->create(['balance' => '10.000000000']);

        // Mock Pixelforge API POST /v1/generations
        Http::fake([
            '*/v1/generations' => Http::response([
                'id' => 'gen_mocked_777',
                'object' => 'generation',
                'status' => 'queued',
                'prompt' => 'A cyberpunk city [succeed]',
                'size' => '1024x1024',
                'quality' => 'medium',
                'rates' => [
                    'input_text_tokens' => '5.00',
                    'input_image_tokens' => '10.00',
                    'output_tokens' => '40.00',
                    'reasoning_tokens' => '12.50',
                ],
            ], 202),
        ]);

        $response = $this->actingAs($user)->post(route('generations.store'), [
            'prompt' => 'A cyberpunk city [succeed]',
            'size' => '1024x1024',
            'quality' => 'medium',
        ]);

        $response->assertRedirect(route('generations.index'));

        $this->assertSame(1, Generation::count());
        $gen = Generation::first();
        $this->assertSame('gen_mocked_777', $gen->pf_generation_id);
        $this->assertSame('queued', $gen->status);
    }

    public function test_poll_endpoint_returns_active_generations_and_fresh_balance(): void
    {
        $user = User::factory()->create(['balance' => '7.500000000']);
        Generation::create([
            'user_id' => $user->id,
            'pf_generation_id' => 'gen_poll_1',
            'prompt' => 'Active generation in progress',
            'status' => 'processing',
        ]);

        $response = $this->actingAs($user)->getJson(route('generations.poll'));

        $response->assertOk()
            ->assertJsonPath('active_count', 1)
            ->assertJsonPath('user_balance', '7.500000000');
    }

    public function test_buying_credits_tops_up_balance(): void
    {
        $user = User::factory()->create(['balance' => '2.000000000']);

        $response = $this->actingAs($user)->post(route('credits.buy'), [
            'amount' => 25,
        ]);

        $response->assertSessionHas('success');
        $this->assertSame('27.000000000', $user->fresh()->balance);
        $this->assertSame(1, $user->creditTransactions()->count());
    }

    public function test_submitting_generation_places_credit_hold_on_available_balance(): void
    {
        $user = User::factory()->create(['balance' => '1.000000000']);

        Http::fake([
            '*/v1/generations' => Http::response([
                'id' => 'gen_hold_test_1',
                'object' => 'generation',
                'status' => 'queued',
                'prompt' => 'A serene lake',
                'size' => '1024x1024',
                'quality' => 'medium',
                'rates' => [
                    'input_text_tokens' => '5.00',
                    'input_image_tokens' => '10.00',
                    'output_tokens' => '40.00',
                    'reasoning_tokens' => '12.50',
                ],
            ], 202),
        ]);

        $response = $this->actingAs($user)->post(route('generations.store'), [
            'prompt' => 'A serene lake',
            'size' => '1024x1024',
            'quality' => 'medium',
        ]);

        $response->assertRedirect(route('generations.index'));

        $gen = Generation::first();
        $this->assertNotNull($gen->hold_amount);
        $this->assertTrue(bccomp((string) $gen->hold_amount, '0', 9) > 0);

        $freshUser = $user->fresh();
        // Reserved balance must match hold amount
        $this->assertSame((string) $gen->hold_amount, (string) $freshUser->reserved_balance);
        // Available balance is balance minus reserved
        $this->assertSame(bcsub('1.000000000', (string) $gen->hold_amount, 9), $freshUser->availableBalance());
    }

    public function test_overdraft_prevention_blocks_subsequent_submissions_when_holds_exceed_balance(): void
    {
        // User has $0.10 (enough for 1 medium generation (~$0.086 max), but not 2)
        $user = User::factory()->create(['balance' => '0.100000000']);

        Http::fake([
            '*/v1/generations' => Http::response([
                'id' => 'gen_overdraft_1',
                'status' => 'queued',
            ], 202),
        ]);

        // First submission succeeds and holds ~$0.07
        $response1 = $this->actingAs($user)->post(route('generations.store'), [
            'prompt' => 'First generation',
            'size' => '1024x1024',
            'quality' => 'medium',
        ]);
        $response1->assertRedirect(route('generations.index'));

        // Second immediate submission must be blocked because hold reduced available balance
        $response2 = $this->actingAs($user)->post(route('generations.store'), [
            'prompt' => 'Second generation',
            'size' => '1024x1024',
            'quality' => 'medium',
        ]);

        $response2->assertSessionHasErrors(['balance']);
        $this->assertSame(1, Generation::count());
    }

    public function test_api_rejection_releases_hold_and_marks_generation_failed(): void
    {
        $user = User::factory()->create(['balance' => '5.000000000']);

        Http::fake([
            '*/v1/generations' => Http::response([
                'error' => [
                    'code' => 'content_policy_violation',
                    'message' => 'Prompt violates content policy.',
                ],
            ], 400),
        ]);

        $response = $this->actingAs($user)->post(route('generations.store'), [
            'prompt' => 'Blocked prompt [blocked]',
            'size' => '1024x1024',
            'quality' => 'medium',
        ]);

        $response->assertRedirect(route('generations.index'));
        $this->assertSame('failed', Generation::first()->status);
        $this->assertSame('content_policy_violation', Generation::first()->error_code);

        // Entire hold must be released
        $this->assertSame('0.000000000', $user->fresh()->reserved_balance);
        $this->assertSame('5.000000000', $user->fresh()->availableBalance());
    }

    public function test_retries_429_rate_limit_and_503_overloaded_with_backoff(): void
    {
        $user = User::factory()->create(['balance' => '5.000000000']);

        Http::fake([
            '*/v1/generations' => Http::sequence()
                ->push(['error' => ['code' => 'rate_limited']], 429, ['Retry-After' => '1'])
                ->push([
                    'id' => 'gen_retry_success',
                    'status' => 'queued',
                ], 202),
        ]);

        $response = $this->actingAs($user)->post(route('generations.store'), [
            'prompt' => 'Retry rate limit test',
            'size' => '1024x1024',
            'quality' => 'medium',
        ]);

        $response->assertRedirect(route('generations.index'));
        $this->assertSame(1, Generation::count());
        $this->assertSame('gen_retry_success', Generation::first()->pf_generation_id);
    }

    public function test_network_timeout_preserves_hold_and_queued_status_to_prevent_free_images(): void
    {
        $user = User::factory()->create(['balance' => '5.000000000']);

        Http::fake([
            '*/v1/generations' => function () {
                throw new ConnectionException('cURL error 28: Operation timed out');
            },
        ]);

        $response = $this->actingAs($user)->post(route('generations.store'), [
            'prompt' => 'Timeout test image',
            'size' => '1024x1024',
            'quality' => 'medium',
        ]);

        $response->assertRedirect(route('generations.index'));
        $response->assertSessionHas('warning');

        $gen = Generation::first();
        // Crucial: Must NOT be failed, and hold must NOT be released!
        $this->assertSame('queued', $gen->status);
        $this->assertSame('network_timeout_pending', $gen->error_code);
        $this->assertNotNull($gen->hold_amount);

        $freshUser = $user->fresh();
        $this->assertSame((string) $gen->hold_amount, (string) $freshUser->reserved_balance);

        // Now simulate the provider finishing in background and delivering succeeded webhook
        $gen->applyPixelforgeData([
            'id' => 'gen_timed_out_but_succeeded_on_provider',
            'status' => 'succeeded',
            'image_url' => 'https://pixelforge.promptedit.dev/images/saved.png',
            'usage' => [
                'input_text_tokens' => 20,
                'input_image_tokens' => 0,
                'output_tokens' => 1100,
                'reasoning_tokens' => 200,
            ],
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
        ]);

        $freshGen = $gen->fresh();
        $this->assertSame('succeeded', $freshGen->status);
        $this->assertSame('https://pixelforge.promptedit.dev/images/saved.png', $freshGen->image_url);

        // User must be charged and hold released!
        $this->assertSame('0.000000000', $user->fresh()->reserved_balance);
        $this->assertSame(1, $user->creditTransactions()->where('type', 'generation_charge')->count());
    }

    public function test_succeeded_event_recovers_and_bills_job_even_if_previously_marked_failed(): void
    {
        $user = User::factory()->create(['balance' => '5.000000000']);
        $gen = Generation::create([
            'user_id' => $user->id,
            'pf_generation_id' => 'gen_prematurely_failed',
            'prompt' => 'Premature failure recovery test',
            'size' => '1024x1024',
            'quality' => 'medium',
            'status' => 'failed',
            'error_code' => 'connection_error',
            'pf_cost' => '0.000000000',
            'user_cost' => '0.000000000',
        ]);

        // Belated succeeded event arrives from provider
        $gen->applyPixelforgeData([
            'id' => 'gen_prematurely_failed',
            'status' => 'succeeded',
            'image_url' => 'https://pixelforge.promptedit.dev/images/recovered.png',
            'usage' => [
                'input_text_tokens' => 20,
                'input_image_tokens' => 0,
                'output_tokens' => 1100,
                'reasoning_tokens' => 200,
            ],
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
        ]);

        $freshGen = $gen->fresh();
        $this->assertSame('succeeded', $freshGen->status);
        $this->assertSame('https://pixelforge.promptedit.dev/images/recovered.png', $freshGen->image_url);
        // User must be charged
        $this->assertSame(1, $user->creditTransactions()->where('type', 'generation_charge')->count());
    }
}
