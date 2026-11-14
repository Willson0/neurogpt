<?php

namespace App\Models\Bot;

use Illuminate\Database\Eloquent\Model;

/** Bot table `prompts`: ready ideas. In the app they are photosets. photo_example is a Telegram file_id or a URL. */
class BotPrompt extends Model
{
    protected $table = 'prompts';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $attributes = ['trend' => false];

    protected function casts(): array
    {
        return ['trend' => 'boolean', 'position' => 'integer'];
    }
}
