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

    public function minPlan(): string
    {
        return $this->only_sub ? 'sub' : 'free';
    }
}
