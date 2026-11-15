<?php

namespace App\Services\Generation;

use App\Models\AiModel;
use App\Models\ModelMeta;

/**
 * Tools of the "Ещё" tab. They are not models of the catalog (the bot table `models` has no tools), so nothing about
 * them is kept in a model table: the text and price are the `tools` setting, how each one runs is `tool_engines`.
 */
class Tools
{
    /** @return array{active:bool, driver:string, provider_model:?string, options:?array} */
    public static function engine(string $slug): array
    {
        $t = config("neurogpt.tools.$slug") ?? [];
        $kieDriver = (string) config('neurogpt.providers.kie_driver');
        $viaKie = ($t['driver'] ?? null) === 'kie' && $kieDriver !== 'unavailable';
        $default = [
            // Without a kie.ai key, upscale and background removal fall back to the image model and its prompt.
            'driver' => $viaKie ? $kieDriver : (string) config('neurogpt.providers.default'),
            'provider_model' => $viaKie ? ($t['provider_model'] ?? null) : config('neurogpt.providers.openai.image_model'),
            'options' => $viaKie ? (array_intersect_key($t, array_flip(['family', 'tier', 'resolution', 'duration', 'factor'])) ?: null) : null,
            // A video tool cannot fall back to an image model.
            'active' => $viaKie || ($t['output'] ?? null) !== 'video',
        ];
        $saved = (array) (config("neurogpt.tool_engines.$slug") ?? []);
        // A placeholder driver saved earlier follows .env, e.g. once KIE_API_KEY appears.
        if (in_array($saved['driver'] ?? null, ['fake', 'unavailable'], true) && $default['driver'] !== $saved['driver']) {
            unset($saved['driver'], $saved['provider_model']);
        }

        return array_merge($default, array_intersect_key($saved, $default));
    }

    public static function isActive(string $slug): bool
    {
        return config("neurogpt.tools.$slug") !== null && self::engine($slug)['active'];
    }

    /** @return list<string> */
    public static function activeSlugs(): array
    {
        return array_values(array_filter(array_keys((array) config('neurogpt.tools')), fn ($slug) => self::isActive((string) $slug)));
    }

    /** What the providers run: a model object that is never saved (a tool has no row in `models`). */
    public static function model(string $slug): ?AiModel
    {
        $t = config("neurogpt.tools.$slug");
        if (! $t) {
            return null;
        }
        $e = self::engine($slug);
        $m = new AiModel;
        $m->setRawAttributes(['type' => 'tool', 'name' => (string) $e['provider_model'], 'text_name' => (string) $t['name'], 'price' => (int) $t['cost']]);
        $m->setRelation('meta', new ModelMeta([
            'slug' => $slug, 'hint' => $t['hint'] ?? null, 'driver' => $e['driver'], 'provider_model' => $e['provider_model'],
            'options' => $e['options'], 'is_active' => $e['active'],
        ]));

        return $m;
    }
}
