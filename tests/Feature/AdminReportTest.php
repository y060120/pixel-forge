<?php

namespace Tests\Feature;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_report_renders_and_reconciles_with_pixelforge_usage(): void
    {
        $user = User::factory()->create();

        $completedDate = now()->format('Y-m-d');

        // Create a completed generation in the database
        Generation::create([
            'user_id' => $user->id,
            'pf_generation_id' => 'gen_reconcile_1',
            'prompt' => 'Reconciliation test',
            'status' => 'succeeded',
            'pf_cost' => '0.100000000',
            'user_cost' => '0.125000000',
            'completed_at' => now(),
        ]);

        // Mock Pixelforge GET /v1/usage to return exact matching provider cost
        Http::fake([
            '*/v1/usage*' => Http::response([
                'object' => 'usage',
                'start_date' => $completedDate,
                'end_date' => $completedDate,
                'data' => [
                    [
                        'date' => $completedDate,
                        'generations' => 1,
                        'input_text_tokens' => 20,
                        'input_image_tokens' => 0,
                        'output_tokens' => 1100,
                        'reasoning_tokens' => 800,
                        'cost_usd' => '0.100000000',
                    ],
                ],
                'total' => [
                    'generations' => 1,
                    'input_text_tokens' => 20,
                    'input_image_tokens' => 0,
                    'output_tokens' => 1100,
                    'reasoning_tokens' => 800,
                    'cost_usd' => '0.100000000',
                ],
            ], 200),
        ]);

        $response = $this->actingAs($user)->get(route('admin.report', [
            'start_date' => $completedDate,
            'end_date' => $completedDate,
        ]));

        $response->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Report')
                ->has('dailyData')
                ->has('userMetrics')
                ->where('totals.reconciled', true)
                ->where('totals.spend', '0.125000000')
                ->where('totals.provider_cost', '0.100000000')
                ->where('totals.profit', '0.025000000')
            );
    }
}
