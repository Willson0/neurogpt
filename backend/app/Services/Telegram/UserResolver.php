<?php

namespace App\Services\Telegram;

use App\Exceptions\InvalidInitData;
use App\Jobs\SyncAvatar;
use App\Models\User;
use App\Services\Billing\Wallet;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    /**
     * Browser sign-in through the Telegram Login Widget. Only admins may use it: everyone else opens the Mini App.
     *
     * @throws InvalidInitData
     */
    public function fromLoginWidget(string $raw): User
    {
        $data = LoginWidget::validate($raw);
        if (! in_array((int) $data['user']['id'], array_map('intval', (array) config('neurogpt.admin_ids')), true)) {
            throw new InvalidInitData('Browser sign-in is for admins only');
        }

        return $this->upsert($data['user']);
    }

    /** Telegram id of the inviter from a start parameter: friend_<id> (ref_<id> is the old format). */
    public static function referrerFrom(?string $startParam): ?int
    {
        return $startParam && preg_match('/^(?:friend|ref)_(\d{1,20})$/', $startParam, $m) ? (int) $m[1] : null;
    }

    public function upsert(array $tg, ?string $startParam = null): User
    {
        $user = User::firstWhere('telegram_id', $tg['id']);
        $photo = (string) ($tg['photo_url'] ?? '');
        $profile = [
            'username' => self::clean($tg['username'] ?? null, 64),
            'first_name' => self::clean($tg['first_name'] ?? null, 128),
            'last_name' => self::clean($tg['last_name'] ?? null, 128),
            'language_code' => self::clean($tg['language_code'] ?? null, 12),
            'photo_url' => preg_match('#^https://[\w.-]+/#', $photo) && strlen($photo) <= 255 ? $photo : null,
        ];

        if ($user) {
            // A locally stored avatar wins over the (often unreachable) Telegram CDN link.
            if (str_starts_with((string) $user->photo_url, '/storage/') || $profile['photo_url'] === null) {
                unset($profile['photo_url']);
            }
            $user->fill($profile)->save();
            $this->syncAvatar($user);

            return $user;
        }

        $referrerTgId = self::referrerFrom($startParam);
        $referrerId = $referrerTgId && $referrerTgId !== (int) $tg['id']
            ? User::where('telegram_id', $referrerTgId)->where('is_blocked', false)->value('id')
            : null;

        try {
            // The account and its welcome bonus appear together or not at all.
            $user = DB::transaction(function () use ($profile, $tg, $referrerId) {
                $user = User::create($profile + ['telegram_id' => $tg['id'], 'referred_by' => $referrerId]);
                if ($bonus = (int) config('neurogpt.bonus.signup')) {
                    $this->wallet->credit($user, $bonus, 'bonus', 'Приветственный бонус');
                }

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // A parallel first request created the user already.
            return User::where('telegram_id', $tg['id'])->firstOrFail();
        }

        $this->syncAvatar($user);

        return $user->refresh();
    }

    private static function clean(mixed $value, int $max): ?string
    {
        return is_string($value) && $value !== '' ? Str::limit($value, $max, '') : null;
    }

    private function syncAvatar(User $user): void
    {
        if (config('neurogpt.telegram.bot_token') && Cache::add("avatar-sync:{$user->id}", 1, now()->addDay())) {
            SyncAvatar::dispatch($user->id);
        }
    }
}
