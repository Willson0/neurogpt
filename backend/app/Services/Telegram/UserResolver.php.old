<?php

namespace App\Services\Telegram;

use App\Exceptions\InvalidInitData;
use App\Models\User;
use App\Services\Billing\Wallet;
use Illuminate\Database\UniqueConstraintViolationException;

class UserResolver
{
    public function __construct(private readonly Wallet $wallet) {}

    /** @throws InvalidInitData */
    public function fromInitData(string $raw): User
    {
        if (config('neurogpt.telegram.allow_dev_auth') && app()->environment('local') && str_starts_with($raw, 'dev:')) {
            $id = (int) substr($raw, 4);
            $data = ['user' => ['id' => $id ?: 1, 'first_name' => 'Dev', 'username' => 'dev'], 'start_param' => null];
        } else {
            $data = InitData::fromConfig()->validate($raw);
        }

        return $this->upsert($data['user'], $data['start_param']);
    }

    public function upsert(array $tg, ?string $startParam = null): User
    {
        $user = User::firstWhere('telegram_id', $tg['id']);
        $profile = [
            'username' => $tg['username'] ?? null,
            'first_name' => $tg['first_name'] ?? null,
            'last_name' => $tg['last_name'] ?? null,
            'language_code' => $tg['language_code'] ?? null,
            'photo_url' => $tg['photo_url'] ?? null,
        ];

        if ($user) {
            $user->fill($profile)->save();

            return $user;
        }

        $referrerId = null;
        if ($startParam && preg_match('/^ref_(\d+)$/', $startParam, $m)) {
            $referrerId = User::where('telegram_id', (int) $m[1])->value('id');
        }

        try {
            $user = User::create($profile + [
                'telegram_id' => $tg['id'],
                'referred_by' => $referrerId,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A parallel first request created the user already.
            return User::where('telegram_id', $tg['id'])->firstOrFail();
        }

        if ($bonus = (int) config('neurogpt.bonus.signup')) {
            $this->wallet->credit($user, $bonus, 'bonus', 'Приветственный бонус');
        }

        return $user->refresh();
    }
}
