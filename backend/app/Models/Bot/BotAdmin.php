<?php

namespace App\Models\Bot;

use Illuminate\Database\Eloquent\Model;

/** Bot table `admins`: id is the Telegram id of a bot admin. They may open the web admin panel too (not as owners). */
class BotAdmin extends Model
{
    protected $table = 'admins';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
