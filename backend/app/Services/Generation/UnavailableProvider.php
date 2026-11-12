<?php

namespace App\Services\Generation;

use App\Exceptions\ProviderUnavailable;
use App\Models\AiModel;
use App\Models\Generation;

/** Placeholder for models whose provider is not connected yet (songs until REPLICATE_SONG_MODEL is set). */
class UnavailableProvider implements Provider
{
    public function chat(AiModel $model, array $messages): string
    {
        throw new ProviderUnavailable('Text is not served by this driver');
    }

    public function media(Generation $generation, AiModel $model, ?string $inputPath): MediaResult
    {
        throw new ProviderUnavailable("No provider is connected for {$model->kind} generation yet");
    }
}
