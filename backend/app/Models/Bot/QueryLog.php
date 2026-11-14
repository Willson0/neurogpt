<?php

namespace App\Models\Bot;

use Illuminate\Database\Eloquent\Model;

/** Bot table `query_logs`: one row per request to a model, for the bot's statistics. */
class QueryLog extends Model
{
    protected $table = 'query_logs';

    const UPDATED_AT = 'modified_at';

    protected $guarded = ['id'];

    protected $attributes = ['was_image_deleted' => false];

    protected function casts(): array
    {
        return ['token_used' => 'integer', 'was_image_deleted' => 'boolean'];
    }
}
