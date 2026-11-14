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
            'kie' => app(KieProvider::class),
            'replicate' => app(ReplicateProvider::class),
            'unavailable' => app(UnavailableProvider::class),
            'fake' => app(FakeProvider::class),
            default => throw new InvalidArgumentException("Unknown driver {$model->driver}"),
        };
    }
}
