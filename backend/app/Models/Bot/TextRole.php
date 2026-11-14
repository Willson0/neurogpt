<?php

namespace App\Models\Bot;

use Illuminate\Database\Eloquent\Model;

/** Bot table `roles`: a system prompt the user chose for text chats. */
class TextRole extends Model
{
    protected $table = 'roles';

    public $timestamps = false;

    protected $guarded = ['id'];
}
