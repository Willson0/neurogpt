<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A button press or a screen view in the Mini App (wa_events), for the admin analytics. */
class Event extends Model
{
    protected $table = 'wa_events';

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'type', 'name', 'screen', 'created_at'];
}
