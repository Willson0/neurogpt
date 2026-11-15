<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** What only the web app knows about a row of the bot table `prompts`: photo or video set, description, cover, colors, on/off. */
class PhotosetMeta extends Model
{
    protected $table = 'wa_photoset_meta';

    protected $primaryKey = 'prompt_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['prompt_id' => 'integer', 'seed' => 'integer', 'palette' => 'integer', 'is_active' => 'boolean', 'edited_at' => 'datetime'];
    }
}
