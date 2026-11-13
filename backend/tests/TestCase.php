<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected const TOKEN = '123456:TEST-TOKEN';

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'neurogpt.telegram.bot_token' => self::TOKEN,
            'neurogpt.telegram.bot_username' => 'neuroai_gptbot',
            'neurogpt.telegram.webhook_secret' => 'whsecret',
            'neurogpt.telegram.allow_dev_auth' => false,
        ]);
        Storage::fake('local');
        Storage::fake('public');
    }

    /** A correctly signed initData string, exactly as Telegram builds it. */
    protected function initData(int $id, ?string $start = null, int $age = 0, string $token = self::TOKEN): string
    {
        $fields = [
            'auth_date' => (string) (time() - $age),
            'query_id' => 'AAH',
            'user' => json_encode(['id' => $id, 'first_name' => 'Ник', 'username' => 'nik'.$id], JSON_UNESCAPED_UNICODE),
        ];
        if ($start) {
            $fields['start_param'] = $start;
        }
        ksort($fields);
        $check = implode("\n", array_map(fn ($k, $v) => "$k=$v", array_keys($fields), $fields));
        $fields['hash'] = hash_hmac('sha256', $check, hash_hmac('sha256', $token, 'WebAppData', true));

        return http_build_query($fields);
    }

    protected function as(int $id, ?string $start = null): static
    {
        return $this->withHeaders(['Authorization' => 'tma '.$this->initData($id, $start), 'Accept' => 'application/json']);
    }

    protected function user(int $id, int $balance = 50, string $plan = 'free'): User
    {
        $this->as($id)->getJson('/api/me')->assertOk();
        $user = User::where('telegram_id', $id)->firstOrFail();
        $user->update(['balance' => $balance, 'plan' => $plan, 'plan_expires_at' => $plan === 'free' ? null : now()->addMonth()]);

        return $user;
    }
}
