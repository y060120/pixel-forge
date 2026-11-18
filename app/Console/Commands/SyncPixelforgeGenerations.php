<?php

namespace App\Console\Commands;

use App\Models\Generation;
use App\Models\User;
use App\Services\PixelforgeClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('pixelforge:sync {--all : Sync all remote generations from Pixelforge API}')]
#[Description('Synchronize generation statuses from Pixelforge API')]
class SyncPixelforgeGenerations extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PixelforgeClient $client): int
    {
        $this->info('Checking pending generations...');

        $pending = Generation::whereIn('status', ['queued', 'processing'])
            ->whereNotNull('pf_generation_id')
            ->get();

        $updatedCount = 0;

        foreach ($pending as $gen) {
            $this->line("Checking generation {$gen->pf_generation_id}...");
            $response = $client->getGeneration($gen->pf_generation_id);

            if ($response['success'] && ! empty($response['data'])) {
                $remoteData = $response['data'];
                $remoteStatus = $remoteData['status'] ?? 'queued';

                if (in_array($remoteStatus, ['succeeded', 'failed'], true)) {
                    $gen->applyPixelforgeData($remoteData);
                    $this->info("✓ Generation {$gen->pf_generation_id} updated to [{$remoteStatus}]");
                    $updatedCount++;
                } else {
                    $this->comment("Generation {$gen->pf_generation_id} is still [{$remoteStatus}]");
                }
            } else {
                $this->warn("Failed to fetch {$gen->pf_generation_id}: ".($response['error']['message'] ?? 'Unknown error'));
            }
        }

        // Reconcile unlinked pending generations (timed out during submission without pf_generation_id)
        $unlinkedPending = Generation::where('status', 'queued')
            ->whereNull('pf_generation_id')
            ->get();

        if ($unlinkedPending->isNotEmpty()) {
            $this->info("Found {$unlinkedPending->count()} unlinked pending generation(s), reconciling with remote list...");
            $listResponse = $client->listGenerations(limit: 50);
            $remoteJobs = collect($listResponse['data']['data'] ?? []);

            foreach ($unlinkedPending as $gen) {
                // Try matching remote generation by metadata.local_id
                $matched = $remoteJobs->first(fn ($item) => (string) ($item['metadata']['local_id'] ?? '') === (string) $gen->id);

                if ($matched) {
                    $gen->pf_generation_id = $matched['id'];
                    $gen->save();
                    $gen->applyPixelforgeData($matched);
                    $this->info("✓ Linked unlinked generation {$gen->id} to {$matched['id']} [{$matched['status']}]");
                    $updatedCount++;
                } elseif ($gen->created_at->lt(now()->subMinutes(15))) {
                    // If after 15 minutes the job never appeared on provider, safely fail and release hold
                    DB::transaction(function () use ($gen) {
                        $locked = Generation::whereKey($gen->id)->lockForUpdate()->first();
                        if ($locked && $locked->status === 'queued') {
                            $locked->update([
                                'status' => 'failed',
                                'error_code' => 'network_timeout_abandoned',
                                'error_message' => 'Generation was never acknowledged by provider after timeout window.',
                                'completed_at' => now(),
                            ]);
                            $u = User::whereKey($locked->user_id)->lockForUpdate()->first();
                            if ($locked->hold_amount) {
                                $u?->releaseCredits($locked->hold_amount);
                            }
                        }
                    });
                    $this->warn("! Timed out unacknowledged generation {$gen->id} expired and hold released.");
                }
            }
        }

        // Check if --all was requested or if we should import missing remote generations
        if ($this->option('all')) {
            $this->info('Fetching remote generations list from Pixelforge...');
            $listResponse = $client->listGenerations(limit: 50);

            if ($listResponse['success'] && ! empty($listResponse['data']['data'])) {
                $defaultUser = User::first();

                foreach ($listResponse['data']['data'] as $remote) {
                    $pfId = $remote['id'] ?? null;
                    if (! $pfId) {
                        continue;
                    }

                    $localGen = Generation::where('pf_generation_id', $pfId)->first();
                    $metadata = $remote['metadata'] ?? [];
                    $localId = $metadata['local_id'] ?? null;

                    if (! $localGen && $localId) {
                        $localGen = Generation::find($localId);
                    }

                    if (! $localGen && $defaultUser) {
                        $localGen = Generation::create([
                            'user_id' => $metadata['user_id'] ?? $defaultUser->id,
                            'pf_generation_id' => $pfId,
                            'prompt' => $remote['prompt'] ?? 'Untitled',
                            'size' => $remote['size'] ?? '1024x1024',
                            'quality' => $remote['quality'] ?? 'medium',
                            'reference_images' => $remote['reference_images'] ?? [],
                            'metadata' => $metadata,
                            'status' => 'queued',
                        ]);
                    }

                    if ($localGen && ! $localGen->isFinal()) {
                        $localGen->applyPixelforgeData($remote);
                        $this->info("✓ Synchronized remote {$pfId} -> [{$remote['status']}]");
                        $updatedCount++;
                    }
                }
            }
        }

        $this->info("Sync completed. {$updatedCount} generation(s) updated.");

        return self::SUCCESS;
    }
}
