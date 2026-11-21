<?php

namespace App\Models;

use App\Models\Bot\BotModel;
use App\Models\Bot\BotTime;
use App\Models\Bot\TextRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * A person = a row of the bot table `users` (models.py User). id is the Telegram id, so the bot and the
 * app always see the same balance and subscription. What the bot schema has no column for lives in
 * wa_user_profiles (UserProfile).
 *
 * Balance = tokens + sub_tokens (while the subscription is active) + temp_tokens (until temp_tokens_until).
 * It is read-only here: every change goes through App\Services\Billing\Wallet.
 */
class User extends Authenticatable
{
    protected $table = 'users';

    public $incrementing = false;

    protected $keyType = 'int';

    const UPDATED_AT = null;

    /** Bot columns the app may write. Balance counters are written only by the Wallet (forceFill). */
    protected $fillable = [
        'username', 'is_premium', 'sub', 'start_sub_time', 'end_sub_time', 'referal_user_from', 'link', 'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'paid_money' => 'float',
            'is_premium' => 'boolean',
            'blocked_us' => 'boolean',
            'tokens' => 'integer',
            'sub_tokens' => 'integer',
            'temp_tokens' => 'integer',
            'temp_tokens_until' => 'integer',
            'save_context' => 'boolean',
            'start_sub_time' => 'integer',
            'end_sub_time' => 'integer',
            'is_trial_sub' => 'boolean',
            'tried_free_smart' => 'boolean',
            'auto_continue' => 'boolean',
            'was_subed' => 'boolean',
            'referal_user_from' => 'integer',
            'ref_paid' => 'boolean',
            'ref_balance' => 'integer',
            'last_activity_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Every NOT NULL column of the bot table with the default from models.py. Tortoise keeps defaults in Python,
     * so a row created by the app has to carry them itself.
     */
    public static function botDefaults(): array
    {
        $now = now();

        return [
            'username' => null, 'email' => null, 'paid_money' => 0.0, 'is_premium' => false, 'blocked_us' => false,
            'tokens' => 0, 'sub_tokens' => 0, 'temp_tokens' => 0, 'temp_tokens_until' => 0,
            'save_context' => true,
            'sub' => 'free', 'start_sub_time' => 0, 'end_sub_time' => 0,
            'autocont_sub' => 'free', 'autocont_try' => null, 'is_trial_sub' => false,
            'tried_free_smart' => false, 'auto_continue' => true, 'last_payment_method_id' => null,
            'was_subed' => false, 'link' => null, 'referal_user_from' => null, 'ref_paid' => false, 'ref_balance' => 0,
            'last_notif' => null, 'last_activity_at' => $now, 'created_at' => $now,
            'text_role_id' => null,
        ];
    }

    // ---------- relations ----------

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class, 'user_id');
    }

    /** The profile row, created in memory when the person has none yet (a bot user opening the app for the first time). */
    public function profileRow(): UserProfile
    {
        if (! $this->relationLoaded('profile')) {
            $this->setRelation('profile', $this->exists ? $this->profile()->first() : null);
        }
        if (! $this->getRelation('profile')) {
            $this->setRelation('profile', new UserProfile(['user_id' => $this->id]));
        }

        return $this->getRelation('profile');
    }

    public function refresh()
    {
        parent::refresh();
        $this->unsetRelation('profile');

        return $this;
    }

    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function chats(): HasMany
    {
        return $this->hasMany(Chat::class);
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referal_user_from');
    }

    public function textRole(): BelongsTo
    {
        return $this->belongsTo(TextRole::class, 'text_role_id');
    }

    public function textModel(): BelongsTo
    {
        return $this->belongsTo(BotModel::class, 'text_model_id');
    }

    // ---------- the app's names for bot columns ----------

    public function getTelegramIdAttribute(): int
    {
        return (int) $this->id;
    }

    public function getReferredByAttribute(): ?int
    {
        return $this->referal_user_from ?: null;
    }

    public function getPlanAttribute(): string
    {
        return (string) ($this->attributes['sub'] ?? 'free') ?: 'free';
    }

    public function getPlanExpiresAtAttribute(): ?Carbon
    {
        return BotTime::toCarbon((int) ($this->attributes['end_sub_time'] ?? 0));
    }

    /** Spendable counters right now, by bot column, in the order they are spent. */
    public function balanceParts(): array
    {
        return [
            'temp_tokens' => (int) $this->temp_tokens_until > BotTime::now() ? max(0, (int) $this->temp_tokens) : 0,
            'sub_tokens' => $this->hasActivePlan() ? max(0, (int) $this->sub_tokens) : 0,
            'tokens' => max(0, (int) $this->tokens),
        ];
    }

    public function getBalanceAttribute(): int
    {
        return array_sum($this->balanceParts());
    }

    // ---------- profile fields (wa_user_profiles) ----------

    public function getFirstNameAttribute(): ?string
    {
        return $this->profileRow()->first_name;
    }

    public function getLastNameAttribute(): ?string
    {
        return $this->profileRow()->last_name;
    }

    public function getLanguageCodeAttribute(): ?string
    {
        return $this->profileRow()->language_code;
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->profileRow()->photo_url;
    }

    public function getStreakAttribute(): int
    {
        return (int) $this->profileRow()->streak;
    }

    public function getLastBonusOnAttribute(): ?Carbon
    {
        return $this->profileRow()->last_bonus_on;
    }

    public function getReferralRewardedAtAttribute(): ?Carbon
    {
        return $this->profileRow()->referral_rewarded_at;
    }

    public function getIsBlockedAttribute(): bool
    {
        return (bool) $this->profileRow()->is_blocked;
    }

    /** Changes profile fields and saves them. */
    public function updateProfile(array $values): void
    {
        $profile = $this->profileRow();
        $profile->user_id = $this->id;
        $profile->fill($values)->save();
    }

    // ---------- subscription ----------

    public function hasActivePlan(): bool
    {
        $sub = (string) ($this->attributes['sub'] ?? 'free');

        return $sub !== '' && $sub !== 'free' && (int) ($this->attributes['end_sub_time'] ?? 0) > BotTime::now();
    }

    /** Active plan name; falls back to free once the subscription has expired. */
    public function activePlan(): string
    {
        return $this->hasActivePlan() ? $this->plan : 'free';
    }

    /** Puts a subscription into the bot's columns (not saved yet). free or no date ends it. */
    public function setPlan(string $plan, ?\DateTimeInterface $until): static
    {
        if ($plan === 'free' || ! $until) {
            return $this->forceFill(['sub' => 'free', 'end_sub_time' => 0]);
        }

        return $this->forceFill([
            'sub' => $plan,
            'start_sub_time' => $this->hasActivePlan() && $this->plan === $plan ? $this->start_sub_time : BotTime::now(),
            'end_sub_time' => BotTime::from($until),
        ]);
    }

    public function scopeSubscribed(Builder $q): Builder
    {
        return $q->whereNotIn('sub', ['', 'free'])->where('end_sub_time', '>', BotTime::now());
    }

    public function scopeBlocked(Builder $q): Builder
    {
        return $q->whereHas('profile', fn ($p) => $p->where('is_blocked', true));
    }

    public function canUse(AiModel $model): bool
    {
        return $model->min_plan === 'free' || $this->hasActivePlan();
    }

    /** https://t.me/<bot>?start=friend_<telegram id>, or null until the bot username is configured. */
    public function inviteLink(): ?string
    {
        $bot = config('neurogpt.telegram.bot_username');

        return $bot ? "https://t.me/{$bot}?start=friend_{$this->id}" : null;
    }

    public function displayName(): string
    {
        return trim(($this->first_name ?? '').' '.($this->last_name ?? '')) ?: ($this->username ?? 'Пользователь');
    }
}
