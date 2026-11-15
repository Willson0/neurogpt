<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Settings the admin can change at runtime. Each key is a path inside config('neurogpt');
 * a stored value replaces the one from config/neurogpt.php and .env. Secrets (API keys, tokens) are not here.
 */
class Settings
{
    /** key => true when the stored array is merged over the defaults instead of replacing them. */
    public const KEYS = [
        'packs' => false,
        'plans' => false,
        'bonus' => true,
        'limits' => true,
        'features' => true,
        'stars' => true,
        'styles' => false,
        'tools' => false,
        'tool_engines' => false,
        'theme' => false,
        'upload_max_kb' => false,
        'telegram.support_url' => false,
        'providers.openai.text_model' => false,
        'providers.openai.image_model' => false,
        'providers.kie.translate' => false,
        // YooKassa shop settings. The secret key is stored encrypted (secret_key_enc) and changed only by an owner.
        'yookassa' => true,
        // Telegram ids of admins added in the panel. Changed only through /api/admin/admins, by an owner.
        'admins' => false,
        // Catalog rows the admin deleted, so the seeder does not bring them back.
        'deleted' => false,
    ];

    /** Loads stored values into config. Called on every boot; quiet until the table exists. */
    public static function apply(): void
    {
        try {
            $rows = DB::table('wa_settings')->pluck('value', 'key');
        } catch (Throwable) {
            return;
        }

        foreach ($rows as $key => $json) {
            if (array_key_exists($key, self::KEYS)) {
                self::set($key, json_decode((string) $json, true));
            }
        }
    }

    public static function get(string $key): mixed
    {
        return config("neurogpt.$key");
    }

    /** Current values of everything the panel edits. */
    public static function all(): array
    {
        $out = [];
        foreach (array_keys(self::KEYS) as $key) {
            $out[$key] = self::get($key);
        }

        return $out;
    }

    public static function put(string $key, mixed $value): void
    {
        abort_unless(array_key_exists($key, self::KEYS), 422, 'Unknown setting');

        DB::table('wa_settings')->updateOrInsert(['key' => $key], [
            'value' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(), 'created_at' => now(),
        ]);
        self::set($key, $value);
        self::restartWorkers();
    }

    /** Queue workers keep the config they booted with, so they are told to restart after a change. */
    public static function restartWorkers(): void
    {
        try {
            Artisan::call('queue:restart');
        } catch (Throwable) {
            // no cache store yet: workers pick the change up on their next restart
        }
    }

    public static function forgetDeleted(string $tag): void
    {
        $list = array_values(array_diff((array) self::get('deleted'), [$tag]));
        if ($list !== (array) self::get('deleted')) {
            self::put('deleted', $list);
        }
    }

    public static function markDeleted(string $tag): void
    {
        self::put('deleted', array_values(array_unique([...(array) self::get('deleted'), $tag])));
    }

    /** The value exactly as stored, without the defaults from config. */
    public static function stored(string $key): mixed
    {
        $json = DB::table('wa_settings')->where('key', $key)->value('value');

        return $json === null ? null : json_decode((string) $json, true);
    }

    private static function set(string $key, mixed $value): void
    {
        if ($key === 'yookassa' && is_array($value)) {
            // Only the encrypted form is stored; config gets the plain key for the API client.
            $enc = $value['secret_key_enc'] ?? null;
            unset($value['secret_key_enc'], $value['secret_key']);
            if ($enc) {
                try {
                    $value['secret_key'] = Crypt::decryptString($enc);
                } catch (Throwable) {
                    // APP_KEY changed: the key must be entered again, the one from .env stays in use
                }
            }
        }
        if (self::KEYS[$key] && is_array($value)) {
            $value = array_replace((array) config("neurogpt.$key"), $value);
        }
        config(["neurogpt.$key" => $value]);
    }
}
