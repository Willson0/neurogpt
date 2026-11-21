<?php

namespace App\Models;

use App\Services\Payments\SubscriptionCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class Payment extends Model
{
    protected $table = 'wa_payments';

    protected $fillable = [
        'user_id', 'rub_amount', 'bot_payment_id', 'provider', 'provider_payment_id', 'idempotence_key', 'purpose', 'tokens', 'plan',
        'period', 'amount', 'currency', 'status', 'confirmation_url', 'cancel_reason', 'credited_at', 'raw',
        'shop_item_id', 'plan_days', 'plan_name',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'rub_amount' => 'decimal:2', 'tokens' => 'integer', 'credited_at' => 'datetime',
            'raw' => 'array', 'shop_item_id' => 'integer', 'plan_days' => 'integer'];
    }

    public function subscriptionName(): string
    {
        return $this->plan_name ?? SubscriptionCatalog::name((string) $this->plan);
    }

    /** Web invoices and completed purchases from the bot, with mirrored invoices counted once. */
    public static function overview(): Builder
    {
        $web = DB::table('wa_payments')->select([
            'id', 'user_id', 'provider', 'provider_payment_id', 'purpose', 'tokens', 'plan', 'plan_name', 'plan_days',
            'period', 'amount', 'currency', 'status', 'cancel_reason', 'raw', 'created_at', 'credited_at',
        ])->selectRaw("'web' AS source, NULL AS item_name");

        $bot = DB::table('payments AS bot')->join('shop AS item', 'item.id', '=', 'bot.item_id')
            ->where('bot.was_bought', true)->where('bot.is_proceed', true)
            ->whereNotExists(fn ($query) => $query->from('wa_payments AS web')->whereColumn('web.bot_payment_id', 'bot.id'))
            ->selectRaw("
                bot.id, bot.user_id, 'bot' AS provider, bot.payment_id AS provider_payment_id,
                CASE WHEN item.category = ? THEN 'plan' WHEN item.category IN ('token', 'tokens') THEN 'topup' ELSE 'other' END AS purpose,
                CASE WHEN item.category IN ('token', 'tokens') THEN item.amount ELSE 0 END AS tokens,
                CASE WHEN item.category = ? THEN item.item END AS plan,
                CASE WHEN item.category = ? THEN item.text_name END AS plan_name,
                CASE WHEN item.category = ? THEN item.amount END AS plan_days,
                NULL AS period, bot.total AS amount, bot.total_valut AS currency, 'succeeded' AS status,
                NULL AS cancel_reason, NULL AS raw, bot.created_at, bot.modified_at AS credited_at,
                'bot' AS source, item.text_name AS item_name", array_fill(0, 4, config('neurogpt.bot.shop.sub_category')));

        return static::query()->fromSub($web->unionAll($bot), 'wa_payments');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
