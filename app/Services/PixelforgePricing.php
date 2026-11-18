<?php

namespace App\Services;

class PixelforgePricing
{
    public const DEFAULT_RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    public const OUTPUT_TOKENS = [
        'low' => [
            '1024x1024' => 280,
            '1536x1024' => 420,
            '1024x1536' => 420,
        ],
        'medium' => [
            '1024x1024' => 1100,
            '1536x1024' => 1650,
            '1024x1536' => 1650,
        ],
        'high' => [
            '1024x1024' => 4300,
            '1536x1024' => 6450,
            '1024x1536' => 6450,
        ],
    ];

    public const REASONING_BOUNDS = [
        'low' => ['min' => 0, 'max' => 500],
        'medium' => ['min' => 200, 'max' => 2000],
        'high' => ['min' => 1000, 'max' => 8000],
    ];

    public const TOKENS_PER_IMAGE = 765;

    public const CHARACTERS_PER_TEXT_TOKEN = 4;

    public const TEXT_TOKEN_VARIANCE = 0.15;

    /**
     * Calculate exact actual cost from reported usage and rates.
     * Failed generations cost $0.00.
     * Costs are truncated to 9 decimal places using BCMath.
     *
     * @param  array<string, int|numeric-string>  $usage
     * @param  array<string, string>|null  $rates
     */
    public static function calculateActualCost(array $usage, ?array $rates = null, int|float|string $markupPercent = '25.0'): array
    {
        $rates = array_merge(self::DEFAULT_RATES, $rates ?? []);

        $textTokens = (string) ($usage['input_text_tokens'] ?? 0);
        $imageTokens = (string) ($usage['input_image_tokens'] ?? 0);
        $outputTokens = (string) ($usage['output_tokens'] ?? 0);
        $reasoningTokens = (string) ($usage['reasoning_tokens'] ?? 0);

        $textRate = bcdiv((string) $rates['input_text_tokens'], '1000000', 12);
        $imageRate = bcdiv((string) $rates['input_image_tokens'], '1000000', 12);
        $outputRate = bcdiv((string) $rates['output_tokens'], '1000000', 12);
        $reasoningRate = bcdiv((string) $rates['reasoning_tokens'], '1000000', 12);

        $textCost = bcmul($textTokens, $textRate, 9);
        $imageCost = bcmul($imageTokens, $imageRate, 9);
        $outputCost = bcmul($outputTokens, $outputRate, 9);
        $reasoningCost = bcmul($reasoningTokens, $reasoningRate, 9);

        $pfCost = bcadd(bcadd(bcadd($textCost, $imageCost, 9), $outputCost, 9), $reasoningCost, 9);

        $markupMultiplier = bcadd('1', bcdiv((string) $markupPercent, '100', 6), 6);
        $userCost = bcmul($pfCost, $markupMultiplier, 9);

        return [
            'pf_cost' => $pfCost,
            'user_cost' => $userCost,
            'breakdown' => [
                'text_cost' => $textCost,
                'image_cost' => $imageCost,
                'output_cost' => $outputCost,
                'reasoning_cost' => $reasoningCost,
            ],
        ];
    }

    /**
     * Estimate cost upfront before submission.
     * Computes expected, minimum, and maximum bounds.
     * Costs are truncated to 9 decimal places using BCMath.
     *
     * @param  array<string, string>|null  $rates
     */
    public static function estimateCost(
        string $prompt,
        string $size = '1024x1024',
        string $quality = 'medium',
        int $referenceImageCount = 0,
        ?array $rates = null,
        int|float|string $markupPercent = '25.0'
    ): array {
        $rates = array_merge(self::DEFAULT_RATES, $rates ?? []);
        $size = in_array($size, ['1024x1024', '1536x1024', '1024x1536'], true) ? $size : '1024x1024';
        $quality = in_array($quality, ['low', 'medium', 'high'], true) ? $quality : 'medium';
        $referenceImageCount = max(0, min(4, $referenceImageCount));

        // Text tokens: ceil(chars / 4), with ±15% variance
        $charCount = mb_strlen($prompt);
        $baseTextTokens = max(1, (int) ceil($charCount / self::CHARACTERS_PER_TEXT_TOKEN));
        $minTextTokens = max(1, (int) floor($baseTextTokens * (1 - self::TEXT_TOKEN_VARIANCE)));
        $maxTextTokens = (int) ceil($baseTextTokens * (1 + self::TEXT_TOKEN_VARIANCE));

        // Image tokens: exactly 765 per reference image
        $imageTokens = $referenceImageCount * self::TOKENS_PER_IMAGE;

        // Output tokens: exact by quality and size
        $outputTokens = self::OUTPUT_TOKENS[$quality][$size] ?? 1100;

        // Reasoning tokens: range by quality
        $reasoningMin = self::REASONING_BOUNDS[$quality]['min'] ?? 200;
        $reasoningMax = self::REASONING_BOUNDS[$quality]['max'] ?? 2000;
        $reasoningExpected = (int) round(($reasoningMin + $reasoningMax) / 2);

        $textRate = bcdiv((string) $rates['input_text_tokens'], '1000000', 12);
        $imageRate = bcdiv((string) $rates['input_image_tokens'], '1000000', 12);
        $outputRate = bcdiv((string) $rates['output_tokens'], '1000000', 12);
        $reasoningRate = bcdiv((string) $rates['reasoning_tokens'], '1000000', 12);

        $imageCost = bcmul((string) $imageTokens, $imageRate, 9);
        $outputCost = bcmul((string) $outputTokens, $outputRate, 9);

        // Expected / Max / Min costs
        $minPfCost = bcadd(
            bcadd(bcmul((string) $minTextTokens, $textRate, 9), $imageCost, 9),
            bcadd($outputCost, bcmul((string) $reasoningMin, $reasoningRate, 9), 9),
            9
        );

        $maxPfCost = bcadd(
            bcadd(bcmul((string) $maxTextTokens, $textRate, 9), $imageCost, 9),
            bcadd($outputCost, bcmul((string) $reasoningMax, $reasoningRate, 9), 9),
            9
        );

        $expectedPfCost = bcadd(
            bcadd(bcmul((string) $baseTextTokens, $textRate, 9), $imageCost, 9),
            bcadd($outputCost, bcmul((string) $reasoningExpected, $reasoningRate, 9), 9),
            9
        );

        $markupMultiplier = bcadd('1', bcdiv((string) $markupPercent, '100', 6), 6);
        $minUserCost = bcmul($minPfCost, $markupMultiplier, 9);
        $maxUserCost = bcmul($maxPfCost, $markupMultiplier, 9);
        $expectedUserCost = bcmul($expectedPfCost, $markupMultiplier, 9);

        return [
            'rates' => $rates,
            'tokens' => [
                'text' => [
                    'base' => $baseTextTokens,
                    'min' => $minTextTokens,
                    'max' => $maxTextTokens,
                ],
                'image' => $imageTokens,
                'output' => $outputTokens,
                'reasoning' => [
                    'expected' => $reasoningExpected,
                    'min' => $reasoningMin,
                    'max' => $reasoningMax,
                ],
            ],
            'provider_cost' => [
                'min' => $minPfCost,
                'max' => $maxPfCost,
                'expected' => $expectedPfCost,
            ],
            'user_cost' => [
                'min' => $minUserCost,
                'max' => $maxUserCost,
                'expected' => $expectedUserCost,
            ],
            'markup_percent' => $markupPercent,
        ];
    }
}
