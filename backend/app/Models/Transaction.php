<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Transaction extends Model
{
    protected $table = 'wa_transactions';

    protected $fillable = [
        'user_id', 'type', 'amount', 'balance_after', 'buckets', 'title', 'details', 'generation_id', 'payment_id',
    ];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'balance_after' => 'integer', 'buckets' => 'array'];
    }

    public static function history(int $userId): Builder
    {
        $web = DB::table('wa_transactions')->where('user_id', $userId)
            ->select(['id', 'type', 'amount', 'title', 'details', 'created_at'])->selectRaw("'web' AS source");
        $queries = DB::table('query_logs AS bot')->where('bot.user_id', $userId)->where('bot.token_used', '>', 0)
            ->whereNotExists(fn ($q) => $q->from('wa_generations AS web')->whereColumn('web.query_log_id', 'bot.id'))
            ->whereNotExists(fn ($q) => $q->from('wa_chat_messages AS web')->whereColumn('web.query_log_id', 'bot.id'))
            ->selectRaw("bot.id, 'spend' AS type, -bot.token_used AS amount, ? AS title, bot.model AS details, bot.created_at, 'bot-query' AS source",
                ['Запрос в боте']);
        $payments = DB::table('payments AS bot')->join('shop AS item', 'item.id', '=', 'bot.item_id')
            ->where('bot.recipient_id', $userId)->where('bot.was_bought', true)->where('bot.is_proceed', true)
            ->whereNotExists(fn ($q) => $q->from('wa_payments AS web')->whereColumn('web.bot_payment_id', 'bot.id'))
            ->selectRaw("bot.id, CASE WHEN item.category = ? THEN 'subscription' ELSE 'topup' END AS type,
                0 AS amount, ? AS title, item.text_name AS details, bot.created_at, 'bot-payment' AS source",
                [config('neurogpt.bot.shop.sub_category'), 'Покупка в боте']);

        return static::query()->fromSub($web->unionAll($queries)->unionAll($payments), 'wa_transactions')
            ->latest('created_at')->orderByDesc('id')->orderBy('source');
    }
}
