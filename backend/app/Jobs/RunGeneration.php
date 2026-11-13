<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\Generation\GenerationRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $generationId) {}

    public function handle(GenerationRunner $runner): void
    {
        $runner->start($this->generationId, $this->queue);
    }

    public function failed(Throwable $e): void
    {
        // Worker killed or timed out: the row is still "processing" with no provider task to poll.
        $g = Generation::find($this->generationId);
        if ($g && $g->isActive() && ! $g->provider_task_id) {
            app(GenerationRunner::class)->fail($g, $e);
        }
    }
}
