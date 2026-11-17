<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
