<?php

namespace App\Console\Commands;

use App\Models\Generation;
use App\Models\User;
use App\Services\PixelforgeClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

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
