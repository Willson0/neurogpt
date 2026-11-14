<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The app's extra data about a person (wa_user_profiles). user_id = users.id = Telegram id. */
class UserProfile extends Model
{
    protected $table = 'wa_user_profiles';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = [
        'user_id', 'first_name', 'last_name', 'language_code', 'photo_url', 'streak', 'last_bonus_on',
        'referral_rewarded_at', 'is_blocked',
    ];

    protected $attributes = ['streak' => 0, 'is_blocked' => false];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'streak' => 'integer',
            'last_bonus_on' => 'date',
            'referral_rewarded_at' => 'datetime',
            'is_blocked' => 'boolean',
        ];
    }
}
