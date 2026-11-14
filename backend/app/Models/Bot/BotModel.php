<?php

namespace App\Models\Bot;

use Illuminate\Database\Eloquent\Model;

/**
 * Bot table `models` (NeuroModel). type: text | photo | video.
 * Mapping to the app: text_name is the name people see, name is the provider's model id.
 */
class BotModel extends Model
{
    protected $table = 'models';

    const UPDATED_AT = 'modified_at';

    protected $guarded = ['id'];

    protected $attributes = ['can_image' => false, 'price' => 0, 'tag' => '', 'only_sub' => false, 'params' => '{}'];

    protected function casts(): array
    {
        return ['can_image' => 'boolean', 'price' => 'integer', 'only_sub' => 'boolean', 'params' => 'array'];
    }

    /** App kind of a bot type and back. */
    public const KINDS = ['text' => 'text', 'photo' => 'image', 'video' => 'video'];

    public static function typeOf(string $kind): ?string
    {
        return array_search($kind, self::KINDS, true) ?: null;
    }

    /**
     * only_sub: the bot only knows "any subscription" or "everybody". The app keeps plus/pro: a model the bot marks
     * only_sub keeps the app's own plan when it is plus or pro ($local), otherwise it needs at least plus.
     */
    public function minPlan(?string $local = null): string
    {
        if (! $this->only_sub) {
            return 'free';
        }

        return in_array($local, ['plus', 'pro'], true) ? $local : 'plus';
    }

    public static function onlySubFor(?string $minPlan): bool
    {
        return in_array($minPlan, ['plus', 'pro'], true);
    }
}
