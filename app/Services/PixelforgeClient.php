<?php

namespace App\Services;

use Exception;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PixelforgeClient
{
    protected string $baseUrl;

    protected string $apiKey;

    protected int $timeout;

    public function __construct(?string $baseUrl = null, ?string $apiKey = null, int $timeout = 15)
    {
        $this->baseUrl = rtrim($baseUrl ?? config('services.pixelforge.base_url', 'https://pixelforge.promptedit.dev/v1'), '/');
        $this->apiKey = $apiKey ?? config('services.pixelforge.api_key', '');
        $this->timeout = $timeout;
    }

    /**
     * Fetch current pricing rates and token formulas.
     * Needs no authentication; cached for 1 hour.
     */
    public function getPricing(bool $fresh = false): array
    {
        if (! $fresh && Cache::has('pixelforge.pricing')) {
            return Cache::get('pixelforge.pricing');
        }

        try {
            $response = Http::timeout($this->timeout)->get("{$this->baseUrl}/pricing");

            if ($response->successful()) {
                $data = $response->json();
                Cache::put('pixelforge.pricing', $data, now()->addHour());

                return $data;
            }
        } catch (Exception $e) {
            Log::warning('Failed to fetch Pixelforge pricing: '.$e->getMessage());
        }

        return [
            'rates' => PixelforgePricing::DEFAULT_RATES,
            'tokens' => [
                'characters_per_text_token' => PixelforgePricing::CHARACTERS_PER_TEXT_TOKEN,
                'text_token_variance' => PixelforgePricing::TEXT_TOKEN_VARIANCE,
                'per_reference_image' => PixelforgePricing::TOKENS_PER_IMAGE,
                'output' => PixelforgePricing::OUTPUT_TOKENS,
                'reasoning' => PixelforgePricing::REASONING_BOUNDS,
            ],
        ];
    }

    /**
     * Create a generation on Pixelforge.
     * Retries automatically on 503 Overloaded if Retry-After is provided.
     *
     * @param array{
     *     prompt: string,
     *     size?: string,
     *     quality?: string,
     *     reference_images?: array<string>,
     *     metadata?: array<string, string>
     * } $params
     */
    public function createGeneration(array $params, ?string $idempotencyKey = null, int $maxRetries = 2): array
    {
        $idempotencyKey = $idempotencyKey ?? (string) Str::uuid();

        // Pixelforge rejects unknown keys with 422, so only send recognized keys
        $payload = [
            'prompt' => $params['prompt'],
            'size' => $params['size'] ?? '1024x1024',
            'quality' => $params['quality'] ?? 'medium',
        ];

        if (! empty($params['reference_images'])) {
            $payload['reference_images'] = array_values(array_slice($params['reference_images'], 0, 4));
        }

        if (! empty($params['metadata'])) {
            $payload['metadata'] = array_slice($params['metadata'], 0, 20);
        }

        $attempt = 0;

        while ($attempt <= $maxRetries) {
            $attempt++;

            try {
                $response = Http::timeout($this->timeout)
                    ->withToken($this->apiKey)
                    ->withHeaders([
                        'Idempotency-Key' => $idempotencyKey,
                        'Accept' => 'application/json',
                    ])
                    ->post("{$this->baseUrl}/generations", $payload);

                if ($response->status() === 202) {
                    return [
                        'success' => true,
                        'status' => 202,
                        'data' => $response->json(),
                        'idempotency_key' => $idempotencyKey,
                    ];
                }

                // If overloaded (503) or rate limited (429), respect Retry-After and retry
                if (in_array($response->status(), [429, 503], true) && $attempt <= $maxRetries) {
                    $retryAfterHeader = $response->header('Retry-After');
                    $retryAfter = is_numeric($retryAfterHeader) ? (int) $retryAfterHeader : 2;
                    sleep(min(max(1, $retryAfter), 5));

                    continue;
                }

                return [
                    'success' => false,
                    'status' => $response->status(),
                    'error' => $this->parseError($response),
                    'idempotency_key' => $idempotencyKey,
                ];
            } catch (\Throwable $e) {
                Log::warning("Pixelforge createGeneration attempt {$attempt} failed: ".$e->getMessage());

                if ($attempt > $maxRetries) {
                    return [
                        'success' => false,
                        'status' => 0,
                        'error' => [
                            'code' => 'connection_error',
                            'message' => 'Connection to Pixelforge failed: '.$e->getMessage(),
                        ],
                        'idempotency_key' => $idempotencyKey,
                    ];
                }

                sleep(1);
            }
        }

        return [
            'success' => false,
            'status' => 503,
            'error' => [
                'code' => 'overloaded',
                'message' => 'Pixelforge service is temporarily overloaded. Please try again.',
            ],
            'idempotency_key' => $idempotencyKey,
        ];
    }

    /**
     * Retrieve a generation by ID.
     */
    public function getGeneration(string $generationId): array
    {
        try {
            $response = Http::timeout($this->timeout)
                ->withToken($this->apiKey)
                ->acceptJson()
                ->get("{$this->baseUrl}/generations/{$generationId}");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'status' => $response->status(),
                    'data' => $response->json(),
                ];
            }

            return [
                'success' => false,
                'status' => $response->status(),
                'error' => $this->parseError($response),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'status' => 0,
                'error' => [
                    'code' => 'connection_error',
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * List generations.
     */
    public function listGenerations(int $limit = 20, ?string $status = null, ?string $cursor = null): array
    {
        $query = ['limit' => min(100, max(1, $limit))];
        if ($status) {
            $query['status'] = $status;
        }
        if ($cursor) {
            $query['cursor'] = $cursor;
        }

        $response = Http::timeout($this->timeout)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->get("{$this->baseUrl}/generations", $query);

        if ($response->successful()) {
            return [
                'success' => true,
                'data' => $response->json(),
            ];
        }

        return [
            'success' => false,
            'error' => $this->parseError($response),
        ];
    }

    /**
     * Retrieve provider usage records for date range.
     */
    public function getUsage(?string $startDate = null, ?string $endDate = null): array
    {
        $query = [];
        if ($startDate) {
            $query['start_date'] = $startDate;
        }
        if ($endDate) {
            $query['end_date'] = $endDate;
        }

        $response = Http::timeout($this->timeout)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->get("{$this->baseUrl}/usage", $query);

        if ($response->successful()) {
            return [
                'success' => true,
                'data' => $response->json(),
            ];
        }

        return [
            'success' => false,
            'status' => $response->status(),
            'error' => $this->parseError($response),
        ];
    }

    protected function parseError(Response $response): array
    {
        $json = $response->json();
        if (isset($json['error'])) {
            return $json['error'];
        }

        return [
            'code' => 'http_'.$response->status(),
            'message' => $response->body() ?: 'Pixelforge API error',
        ];
    }
}
