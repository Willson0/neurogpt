<?php

namespace App\Models\Bot;

use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Bot table `config` (models.py, Config): category + name + value, e.g. "daily_tokens / pro / 200".
 * The app reads the bot's settings from here and keeps its own bot-visible values here too: the price of every tool
 * of the "Ещё" tab is a row "tools / <slug> / <tokens>" (the bot table `models` has no tools).
 */
class BotConfig extends Model
{
    protected $table = 'config';

    const UPDATED_AT = 'modified_at';

    public const TOOLS = 'tools';

    public const DAILY_TOKENS = 'daily_tokens';

    protected $guarded = ['id'];

    /** @var array<string, array<string, string>>|null rows of this request, category => name => value */
    private static ?array $cache = null;

    private static float $loadedAt = 0;

    /** @return array<string, string> name => value of one category ([] when the table is missing) */
    public static function category(string $category): array
    {
        // re-read after a short while: queue workers live for hours and the bot may change a value
        if (self::$cache === null || microtime(true) - self::$loadedAt > 30) {
            self::$cache = [];
            self::$loadedAt = microtime(true);
            try {
                foreach (self::query()->orderBy('id')->get(['category', 'name', 'value']) as $row) {
                    // the first row of a name wins, as a lookup in the bot would find it
                    self::$cache[$row->category][$row->name] ??= (string) $row->value;
                }
            } catch (Throwable) {
                // no `config` table on this database
            }
        }

        return self::$cache[$category] ?? [];
    }

    public static function int(string $category, string $name): ?int
    {
        $v = self::category($category)[$name] ?? null;

        return $v !== null && is_numeric($v) ? (int) $v : null;
    }

    /** Writes one value (updates the first row of that name or adds one). */
    public static function put(string $category, string $name, string $value): void
    {
        $row = self::query()->where(['category' => $category, 'name' => $name])->orderBy('id')->first();
        $row ? $row->update(['value' => $value]) : self::query()->create(['category' => $category, 'name' => $name, 'value' => $value]);
        self::flush();
    }

    public static function forget(string $category, string $name): void
    {
        self::query()->where(['category' => $category, 'name' => $name])->delete();
        self::flush();
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
