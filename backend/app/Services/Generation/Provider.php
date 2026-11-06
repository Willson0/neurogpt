<?php

namespace App\Services\Generation;

use App\Models\AiModel;
use App\Models\Generation;

interface Provider
{
    /** One chat completion. $messages is a list of ['role' => ..., 'content' => ...]. */
    public function chat(AiModel $model, array $messages): string;

    /** Image or video: returns the raw file bytes and a file extension. */
    public function media(Generation $generation, AiModel $model, ?string $inputPath): MediaResult;
}
