<?php

namespace App\Models;

use App\Models\Bot\ShopItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A payment = a row of the bot table `payments` (models.py, PaymentModel). Every payment of the web app is written
 * there from the start, so the bot and the app see the same list:
 *
 *   is_proceed = 0, was_bought = 0   waiting for the money
 *   is_proceed = 1, was_bought = 1   paid and delivered
 *   is_proceed = 1, was_bought = 0   canceled / expired
 *
 * payment_id = the YooKassa payment id (a UUID until YooKassa answered) or the Telegram Stars charge id (a UUID until paid).
 * total + total_valut = what is charged (RUB, or XTR for Stars), rub_total = the ruble price after the promo discount.
 *
 * What the bot table has no column for lives in wa_payment_details (details()). Rows the bot made itself have no details:
 * the accessors below then derive everything from the bot columns.
 */
class Payment extends Model
{
    protected $table = 'payments';

    const UPDATED_AT = 'modified_at';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_autopayment' => 'boolean', 'was_bought' => 'boolean', 'is_proceed' => 'boolean',
            'total' => 'float', 'rub_total' => 'float', 'item_id' => 'integer', 'user_id' => 'integer', 'recipient_id' => 'integer',
        ];
    }

    public function details(): HasOne
    {
        return $this->hasOne(PaymentDetail::class, 'payment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ShopItem::class, 'item_id');
    }

    // ---------- the state, from the bot flags ----------

    public static function stateOf(bool $proceed, bool $bought, ?string $detail = null): string
    {
        if ($bought) {
            return $detail === 'refunded' ? 'refunded' : 'succeeded';
        }

        return $proceed ? 'canceled' : 'pending';
    }

    protected function status(): Attribute
    {
        return Attribute::get(fn () => self::stateOf((bool) $this->is_proceed, (bool) $this->was_bought, $this->details?->status));
    }

    /** Payments in a state, by the bot flags (refunded ones are a part of the bought ones). */
    public function scopeState(Builder $q, string $state): Builder
    {
        return match ($state) {
            'pending' => $q->where('payments.is_proceed', false)->where('payments.was_bought', false),
            'canceled' => $q->where('payments.is_proceed', true)->where('payments.was_bought', false),
            'succeeded' => $q->where('payments.was_bought', true)->whereDoesntHave('details', fn ($d) => $d->where('status', 'refunded')),
            'refunded' => $q->where('payments.was_bought', true)->whereHas('details', fn ($d) => $d->where('status', 'refunded')),
            'paid' => $q->where('payments.was_bought', true),
            default => $q,
        };
    }

    public function scopeProvider(Builder $q, string $provider): Builder
    {
        return $provider === 'stars' ? $q->where('payments.total_valut', 'XTR') : $q->where('payments.total_valut', '!=', 'XTR');
    }

    /** Only payments the web app made (it can check and change those). */
    public function scopeOwn(Builder $q): Builder
    {
        return $q->whereHas('details');
    }

    // ---------- read-only views used across the app ----------

    protected function provider(): Attribute
    {
        return Attribute::get(fn () => $this->details?->provider ?? ($this->total_valut === 'XTR' ? 'stars' : 'yookassa'));
    }

    protected function currency(): Attribute
    {
        return Attribute::get(fn () => $this->total_valut ?: 'RUB');
    }

    protected function amount(): Attribute
    {
        return Attribute::get(fn () => (float) $this->total);
    }

    protected function rubAmount(): Attribute
    {
        return Attribute::get(fn () => (float) $this->rub_total);
    }

    protected function providerPaymentId(): Attribute
    {
        return Attribute::get(fn () => $this->payment_id);
    }

    protected function shopItemId(): Attribute
    {
        return Attribute::get(fn () => $this->item_id);
    }

    protected function idempotenceKey(): Attribute
    {
        return Attribute::get(fn () => $this->details?->idempotence_key);
    }

    protected function purpose(): Attribute
    {
        return Attribute::get(fn () => $this->details?->purpose
            ?? ($this->item && $this->item->category === config('neurogpt.bot.shop.sub_category') ? 'plan' : 'topup'));
    }

    protected function tokens(): Attribute
    {
        return Attribute::get(fn () => $this->details ? (int) $this->details->tokens
            : ($this->item && $this->item->category === config('neurogpt.bot.shop.tokens_category') ? (int) $this->item->amount : 0));
    }

    protected function plan(): Attribute
    {
        return Attribute::get(fn () => $this->details?->plan ?? ($this->purpose === 'plan' ? $this->item?->item : null));
    }

    protected function period(): Attribute
    {
        return Attribute::get(fn () => $this->details?->period);
    }

    protected function confirmationUrl(): Attribute
    {
        return Attribute::get(fn () => $this->details?->confirmation_url);
    }

    protected function creditedAt(): Attribute
    {
        return Attribute::get(fn () => $this->details?->credited_at);
    }
}
