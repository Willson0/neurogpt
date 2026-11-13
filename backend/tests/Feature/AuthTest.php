<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_requests_without_valid_telegram_signature_are_rejected(): void
    {
        $this->getJson('/api/me')->assertStatus(401);
        $good = $this->initData(1001);
        $this->withHeaders(['Authorization' => 'tma '.substr($good, 0, -3).'000'])->getJson('/api/me')->assertStatus(401);
        $this->withHeaders(['Authorization' => 'tma '.$this->initData(1001, age: 999999)])->getJson('/api/me')->assertStatus(401);
        $this->withHeaders(['Authorization' => 'tma '.$this->initData(1001, token: '1:OTHER')])->getJson('/api/me')->assertStatus(401);
        // The development shortcut must not work outside the local environment.
        config(['neurogpt.telegram.allow_dev_auth' => true]);
        $this->withHeaders(['Authorization' => 'tma dev:1'])->getJson('/api/me')->assertStatus(401);
    }

    public function test_tampered_fields_break_the_signature(): void
    {
        parse_str($this->initData(1001), $fields);
        $fields['user'] = json_encode(['id' => 1, 'first_name' => 'Admin']);
        $this->withHeaders(['Authorization' => 'tma '.http_build_query($fields)])->getJson('/api/me')->assertStatus(401);
        // PHP-style array keys must not slip through as a different data-check string.
        $this->withHeaders(['Authorization' => 'tma '.$this->initData(1001).'&user[id]=1'])->getJson('/api/me')->assertStatus(401);
    }

    public function test_first_request_creates_the_user_with_a_welcome_bonus(): void
    {
        $this->as(1001)->postJson('/api/auth/telegram')->assertOk()->assertJsonPath('balance', 50)
            ->assertJsonPath('invite_link', 'https://t.me/neuroai_gptbot?start=friend_1001');
        $this->as(1001)->getJson('/api/me')->assertJsonPath('balance', 50);
        $this->assertSame(1, User::count());
    }

    public function test_bot_username_with_at_sign_still_gives_a_clean_link(): void
    {
        config(['neurogpt.telegram.bot_username' => ltrim('@neuroai_gptbot', '@')]);
        $this->assertSame('https://t.me/neuroai_gptbot?start=friend_77', (new User(['telegram_id' => 77]))->inviteLink());
        $this->assertStringNotContainsString('@', (string) (new User(['telegram_id' => 77]))->inviteLink());
    }

    public function test_blocked_user_gets_403(): void
    {
        $this->user(5)->update(['is_blocked' => true]);
        $this->as(5)->getJson('/api/me')->assertStatus(403);
    }

    public function test_referral_is_registered_from_friend_and_legacy_ref_links(): void
    {
        $inviter = $this->user(100);
        $this->as(201, 'friend_100')->getJson('/api/me')->assertOk();
        $this->as(202, 'ref_100')->getJson('/api/me')->assertOk();
        $this->as(203, 'friend_203')->getJson('/api/me')->assertOk(); // inviting yourself does nothing
        $this->as(204, 'friend_100; drop')->getJson('/api/me')->assertOk();

        $this->assertSame($inviter->id, User::where('telegram_id', 201)->value('referred_by'));
        $this->assertSame($inviter->id, User::where('telegram_id', 202)->value('referred_by'));
        $this->assertNull(User::where('telegram_id', 203)->value('referred_by'));
        $this->assertNull(User::where('telegram_id', 204)->value('referred_by'));
    }

    public function test_bootstrap_hides_songs_and_prompts(): void
    {
        $res = $this->as(1001)->getJson('/api/bootstrap')->assertOk();
        $res->assertJsonPath('features.songs', false)->assertJsonMissingPath('models.song');
        $this->assertCount(5, $res->json('models.video'));
        $this->assertCount(12, $res->json('tools'));
        $this->assertStringNotContainsString('Keep the face', $res->getContent());
        $this->assertArrayNotHasKey('prompt', $res->json('tools.0'));
        $this->assertArrayNotHasKey('prompt', $res->json('photosets.0'));
    }

    public function test_daily_bonus_is_granted_once_a_day(): void
    {
        $this->as(1)->postJson('/api/bonus/daily')->assertJsonPath('granted', true)->assertJsonPath('user.balance', 55);
        $this->as(1)->postJson('/api/bonus/daily')->assertJsonPath('granted', false)->assertJsonPath('user.balance', 55);
    }
}
