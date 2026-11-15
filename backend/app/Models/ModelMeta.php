<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What only the web app knows about a row of the bot table `models`: its short id in links (slug), the hint and badge
 * on the card, the provider that runs it here, video options, order and on/off in the app. One row per models.id.
 */
class ModelMeta extends Model
{
    protected $table = 'wa_model_meta';

    protected $primaryKey = 'model_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['model_id' => 'integer', 'is_fast' => 'boolean', 'is_active' => 'boolean', 'options' => 'array', 'sort' => 'integer', 'edited_at' => 'datetime'];
    }
}
