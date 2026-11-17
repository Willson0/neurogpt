<?php

namespace App\Services\Telegram;

use App\Exceptions\InvalidInitData;
use App\Http\Middleware\AdminOnly;
use App\Jobs\SyncAvatar;
use App\Models\Bot\BotLink;
use App\Models\User;
use App\Services\Billing\Wallet;
use App\Services\Bot\BotSync;
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
        if (! AdminOnly::allowsId((int) $data['user']['id'])) {
            throw new InvalidInitData('Browser sign-in is for admins only');
        }

        return $this->upsert($data['user']);
    }

    /** Telegram id of the inviter from a start parameter: friend_<id> (ref_<id> is the old format). */
    public static function referrerFrom(?string $startParam): ?int
    {
        return $startParam && preg_match('/^(?:friend|ref)_(\d{1,20})$/', $startParam, $m) ? (int) $m[1] : null;
    }

    /**
     * The person behind a signed Telegram payload. A bot user is found by id (= Telegram id) and only gets the app
     * profile and fresh username/premium/activity; the bot counters are not touched. Someone who never started the
     * bot gets a full row in the bot table `users` (every column from models.py), with no bonus at start.
     */
    public function upsert(array $tg, ?string $startParam = null): User
    {
        $id = (int) $tg['id'];
        $photo = (string) ($tg['photo_url'] ?? '');
        $profile = [
            'first_name' => self::clean($tg['first_name'] ?? null, 128),
            'last_name' => self::clean($tg['last_name'] ?? null, 128),
            'language_code' => self::clean($tg['language_code'] ?? null, 12),
            'photo_url' => preg_match('#^https://[\w.-]+/#', $photo) && strlen($photo) <= 255 ? $photo : null,
        ];
        $username = self::clean($tg['username'] ?? null, 64);

        $user = User::with('profile')->find($id);
        if ($user) {
            // A locally stored avatar wins over the (often unreachable) Telegram CDN link.
            if (str_starts_with((string) $user->photo_url, '/storage/') || $profile['photo_url'] === null) {
                unset($profile['photo_url']);
            }
            $user->updateProfile($profile);
            $this->touchBotRow($user, $username, $tg);
            $this->syncAvatar($user);

            return $user;
        }

        $referrerTgId = self::referrerFrom($startParam);
        $referrerId = $referrerTgId && $referrerTgId !== $id && User::whereKey($referrerTgId)->exists()
            && ! User::whereKey($referrerTgId)->blocked()->exists() ? $referrerTgId : null;
        // A start parameter that names a bot integration link (table `links`) is kept in users.link, as the bot does.
        $link = $startParam && ! $referrerTgId && BotLink::where('name', $startParam)->exists() ? $startParam : null;

        try {
            // No tokens at start: the referral tokens come with the first purchase (PaymentService::creditReferralTokens).
            $user = DB::transaction(function () use ($id, $username, $tg, $referrerId, $link, $profile) {
                $user = new User;
                $user->forceFill(User::botDefaults() + BotSync::defaultModelIds());
                $user->forceFill([
                    'id' => $id, 'username' => $username, 'is_premium' => (bool) ($tg['is_premium'] ?? false),
                    'referal_user_from' => $referrerId, 'link' => $link,
                ]);
                $user->save();
                $user->updateProfile($profile);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // A parallel first request (or the bot) created the user already.
            return User::findOrFail($id);
        }

        $this->syncAvatar($user);

        return $user->refresh();
    }

    /** username and Telegram Premium follow Telegram; last_activity_at is refreshed at most every 5 minutes. */
    private function touchBotRow(User $user, ?string $username, array $tg): void
    {
        $changes = [];
        if ($username !== null && $username !== $user->username) {
            $changes['username'] = $username;
        }
        if (array_key_exists('is_premium', $tg) && (bool) $tg['is_premium'] !== (bool) $user->is_premium) {
            $changes['is_premium'] = (bool) $tg['is_premium'];
        }
        if (! $user->last_activity_at || $user->last_activity_at->lt(now()->subMinutes(5))) {
            $changes['last_activity_at'] = now();
        }
        if ($changes) {
            // Only these columns are written, so a parallel balance change by the bot is not overwritten.
            User::whereKey($user->id)->update($changes);
            $user->forceFill($changes)->syncOriginalAttributes(array_keys($changes));
        }
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
