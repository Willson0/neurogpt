<?php

namespace App\Services\Generation;

use App\Models\AiModel;
use InvalidArgumentException;

class ProviderManager
{
    public function for(AiModel $model): Provider
    {
        return match ($model->driver ?: config('neurogpt.providers.default')) {
            'openai' => app(OpenAiProvider::class),
            'replicate' => app(ReplicateProvider::class),
            'fake' => app(FakeProvider::class),
            default => throw new InvalidArgumentException("Unknown driver {$model->driver}"),
        };
    }
}
