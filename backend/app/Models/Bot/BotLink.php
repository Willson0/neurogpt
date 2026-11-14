<?php

namespace App\Models\Bot;

use Illuminate\Database\Eloquent\Model;

/** Bot table `links`: named start links of ad integrations (users.link keeps the name). */
class BotLink extends Model
{
    protected $table = 'links';

    const UPDATED_AT = 'modified_at';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['hide' => 'boolean'];
    }
}
