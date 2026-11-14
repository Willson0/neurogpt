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

    protected $attributes = ['can_image' => false, 'price' => 0, 'tag' => '', 'params' => '{}'];

    protected function casts(): array
    {
        return ['can_image' => 'boolean', 'price' => 'integer', 'allowed_subs' => 'array', 'params' => 'array'];
    }

    /** App kind of a bot type and back. */
    public const KINDS = ['text' => 'text', 'photo' => 'image', 'video' => 'video'];

    public static function typeOf(string $kind): ?string
    {
        return array_search($kind, self::KINDS, true) ?: null;
    }

    /** allowed_subs: null = everybody, otherwise the list of subscriptions. The app keeps the lowest one. */
    public function minPlan(): string
    {
        $subs = $this->allowed_subs;
        if (! is_array($subs) || $subs === [] || in_array('free', $subs, true)) {
            return 'free';
        }
        $rank = ['free' => 0, 'plus' => 1, 'pro' => 2];
        usort($subs, fn ($a, $b) => ($rank[$a] ?? 9) <=> ($rank[$b] ?? 9));

        return (string) $subs[0];
    }

    public static function subsFor(string $minPlan): ?array
    {
        return match ($minPlan) {
            'plus' => ['plus', 'pro'],
            'pro' => ['pro'],
            default => null,
        };
    }
}
