<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    protected $table = 'wa_chat_messages';

    protected $fillable = ['chat_id', 'role', 'content', 'cost', 'query_log_id'];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }
}
