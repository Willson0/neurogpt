<?php

namespace App\Services\Generation;

use App\Models\AiModel;
use App\Models\Bot\BotConfig;
use App\Models\ModelMeta;

/**
 * Tools of the "Ещё" tab. They are not models of the catalog (the bot table `models` has no tools), so nothing about
 * them is kept in a model table: the text is the `tools` setting, how each one runs is `tool_engines`, and the PRICE
 * is in the bot database, table `config`: a row "tools / <slug> / <tokens>" (neurogpt:sync-bot adds a missing row,
 * the admin panel writes it). The `cost` of the setting is only the fallback when there is no such row.
 */
class Tools
{
    /** Tokens one run of the tool costs: the bot table `config` first, then the setting. */
    public static function cost(string $slug): int
    {
        return BotConfig::int(BotConfig::TOOLS, $slug) ?? (int) (config("neurogpt.tools.$slug.cost") ?? 0);
    }

    /** Writes the price into the bot table `config` (missing tools get a row with their current price). */
    public static function putCost(string $slug, int $cost): void
    {
        BotConfig::put(BotConfig::TOOLS, $slug, (string) max(0, $cost));
    }

    /** Adds a `config` row for every tool that has none yet. @return int rows added */
    public static function seedCosts(): int
    {
        $have = BotConfig::category(BotConfig::TOOLS);
        $added = 0;
        foreach (array_keys((array) config('neurogpt.tools')) as $slug) {
            if (! array_key_exists($slug, $have)) {
                self::putCost((string) $slug, (int) config("neurogpt.tools.$slug.cost"));
                $added++;
            }
        }

        return $added;
    }

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
        $m->setRawAttributes(['type' => 'tool', 'name' => (string) $e['provider_model'], 'text_name' => (string) $t['name'], 'price' => self::cost($slug)]);
        $m->setRelation('meta', new ModelMeta([
            'slug' => $slug, 'hint' => $t['hint'] ?? null, 'driver' => $e['driver'], 'provider_model' => $e['provider_model'],
            'options' => $e['options'], 'is_active' => $e['active'],
        ]));

        return $m;
    }
}
