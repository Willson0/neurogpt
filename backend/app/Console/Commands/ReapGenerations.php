<?php

namespace App\Console\Commands;

use App\Jobs\PollGeneration;
use App\Models\Generation;
use App\Services\Generation\GenerationRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ReapGenerations extends Command
{
    protected $signature = 'generations:reap';

    protected $description = 'Refund generations that got stuck and delete forgotten uploads';

    public function handle(GenerationRunner $runner): int
    {
        $timeout = (int) config('neurogpt.limits.generation_timeout');

        // A task that is still being polled is picked up again (the poll job may have been lost in a restart).
        Generation::where('status', 'processing')->whereNotNull('provider_task_id')
            ->where('started_at', '<', now()->subSeconds(120))->where('started_at', '>=', now()->subSeconds($timeout))
            ->where('updated_at', '<', now()->subSeconds(120))
            ->each(function (Generation $g) {
                $g->touch();
                PollGeneration::dispatch($g->id, 20);
            });

        $stuck = Generation::query()
            ->where(fn ($q) => $q->where('status', 'processing')->where('started_at', '<', now()->subSeconds($timeout + 120)))
            ->orWhere(fn ($q) => $q->where('status', 'queued')->where('created_at', '<', now()->subHours(3)))
            ->get();
        foreach ($stuck as $g) {
            $runner->fail($g, new RuntimeException('Reaped: no result in time'));
        }

        // Uploads older than a day belong to finished generations.
        $disk = Storage::disk('local');
        $deleted = 0;
        foreach ($disk->allFiles('inputs') as $file) {
            if ($disk->lastModified($file) < now()->subDay()->getTimestamp()) {
                $disk->delete($file);
                $deleted++;
            }
        }

        $this->info("Refunded {$stuck->count()} stuck generations, deleted {$deleted} old uploads.");

        return self::SUCCESS;
    }
}
