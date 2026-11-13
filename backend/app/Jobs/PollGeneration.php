<?php

namespace App\Jobs;

use App\Services\Generation\GenerationRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Checks a task submitted to an async provider and re-queues itself until the task ends.
 * A lost poll is not fatal: `generations:reap` refunds anything that stays "processing" too long.
 */
class PollGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 420;

    public function __construct(public int $generationId, public int $attempt = 1) {}

    public function handle(GenerationRunner $runner): void
    {
        if ($runner->poll($this->generationId)) {
            return;
        }

        // 6 s for the first minute, then 12 s: quick tools feel instant, long videos do not hammer the API.
        self::dispatch($this->generationId, $this->attempt + 1)
            ->onQueue($this->queue)
            ->delay(now()->addSeconds($this->attempt < 10 ? 6 : 12));
    }
}
