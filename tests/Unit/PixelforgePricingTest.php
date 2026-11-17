<?php

namespace Tests\Unit;

use App\Services\PixelforgePricing;
use PHPUnit\Framework\TestCase;

class PixelforgePricingTest extends TestCase
{
    /**
     * Test calculation matches the official worked example from Pixelforge documentation.
     * High quality 1536x1024, 400-char prompt (115 text tokens), 2 reference images (1530 tokens),
     * 6450 output tokens, 8000 reasoning tokens.
     */
    public function test_matches_official_documentation_worked_example(): void
    {
        $usage = [
            'input_text_tokens' => 115,
            'input_image_tokens' => 1530,
            'output_tokens' => 6450,
            'reasoning_tokens' => 8000,
            'total_tokens' => 10095,
        ];

        $rates = [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ];

        $result = PixelforgePricing::calculateActualCost($usage, $rates, 25.0);

        // Pixelforge provider cost must match 0.373875000 exactly
        $this->assertSame('0.373875000', $result['pf_cost']);

        // Breakdown check
        $this->assertSame('0.000575000', $result['breakdown']['text_cost']);
        $this->assertSame('0.015300000', $result['breakdown']['image_cost']);
        $this->assertSame('0.258000000', $result['breakdown']['output_cost']);
        $this->assertSame('0.100000000', $result['breakdown']['reasoning_cost']);

        // User cost with 25% markup: 0.373875000 * 1.25 = 0.467343750
        $this->assertSame('0.467343750', $result['user_cost']);
    }

    /**
     * Test upfront cost estimator bounds and formulas.
     */
    public function test_upfront_cost_estimation(): void
    {
        $prompt = str_repeat('a', 400); // 400 characters -> base 100 tokens
        $estimate = PixelforgePricing::estimateCost(
            $prompt,
            '1536x1024',
            'high',
            2,
            PixelforgePricing::DEFAULT_RATES,
            25.0
        );

        $this->assertSame(100, $estimate['tokens']['text']['base']);
        $this->assertSame(115, $estimate['tokens']['text']['max']);
        $this->assertSame(1530, $estimate['tokens']['image']); // 2 * 765
        $this->assertSame(6450, $estimate['tokens']['output']);
        $this->assertSame(8000, $estimate['tokens']['reasoning']['max']);

        // Max provider cost in estimator must equal maximum worked example
        $this->assertSame('0.373875000', $estimate['provider_cost']['max']);
        $this->assertSame('0.467343750', $estimate['user_cost']['max']);
    }

    /**
     * Test empty or zero tokens edge cases.
     */
    public function test_zero_and_empty_usage(): void
    {
        $result = PixelforgePricing::calculateActualCost([], null, 25.0);
        $this->assertSame('0.000000000', $result['pf_cost']);
        $this->assertSame('0.000000000', $result['user_cost']);
    }
}
