<?php

namespace App\Services\Generation;

use App\Models\AiModel;
use App\Models\Generation;

/**
 * A provider whose jobs take minutes: the task is submitted once and then polled by a
 * delayed job, so no queue worker sits blocked while a video renders.
 */
interface AsyncProvider
{
    /** Starts the task and returns its id on the provider's side. */
    public function submit(Generation $generation, AiModel $model, ?string $inputPath): string;

    /** Returns the finished file, or null while the task is still running. Throws when it failed. */
    public function fetch(Generation $generation, AiModel $model): ?MediaResult;
}
