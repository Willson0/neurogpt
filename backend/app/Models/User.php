<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $fillable = [
        'telegram_id', 'username', 'first_name', 'last_name', 'language_code', 'photo_url',
        'balance', 'plan', 'plan_expires_at', 'streak', 'last_bonus_on', 'referred_by',
        'referral_rewarded_at', 'is_blocked',
    ];

    protected function casts(): array
    {
        return [
            'telegram_id' => 'integer',
            'balance' => 'integer',
            'plan_expires_at' => 'datetime',
            'last_bonus_on' => 'date',
            'referral_rewarded_at' => 'datetime',
            'is_blocked' => 'boolean',
        ];
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
        return $this->belongsTo(self::class, 'referred_by');
    }

    /** Active plan name; falls back to free once the subscription has expired. */
    public function activePlan(): string
    {
        if ($this->plan !== 'free' && $this->plan_expires_at && $this->plan_expires_at->isPast()) {
            return 'free';
        }

        return $this->plan;
    }

    public function canUse(AiModel $model): bool
    {
        $rank = ['free' => 0, 'plus' => 1, 'pro' => 2];

        return $rank[$this->activePlan()] >= ($rank[$model->min_plan] ?? 0);
    }

    public function displayName(): string
    {
        return trim(($this->first_name ?? '').' '.($this->last_name ?? '')) ?: ($this->username ?? 'Пользователь');
    }
}
