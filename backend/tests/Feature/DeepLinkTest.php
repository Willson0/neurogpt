<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Telegram\Bot;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeepLinkTest extends TestCase
{
    private function start(string $text, int $id = 501): array
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'whsecret'])->postJson('/api/telegram/webhook', ['message' => [
            'message_id' => 1, 'text' => $text, 'chat' => ['id' => $id, 'type' => 'private'], 'from' => ['id' => $id, 'first_name' => 'Ник'],
        ]])->assertOk();

        $sent = collect(Http::recorded())->first(fn ($pair) => str_ends_with($pair[0]->url(), '/sendMessage'));
        $this->assertNotNull($sent);

        return $sent[0]->data();
    }

    public function test_start_with_a_screen_gives_a_button_to_that_screen(): void
    {
        config(['neurogpt.telegram.webapp_url' => 'https://app.test']);
        $data = $this->start('/start plans-pro');

        $this->assertSame('https://app.test/?startapp=plans-pro', $data['reply_markup']['inline_keyboard'][0][0]['web_app']['url']);
    }

    public function test_referral_start_keeps_the_plain_button_and_registers_the_inviter(): void
    {
        config(['neurogpt.telegram.webapp_url' => 'https://app.test/']);
        $inviter = $this->user(777);
        $data = $this->start('/start friend_777');

        $this->assertSame('https://app.test/', $data['reply_markup']['inline_keyboard'][0][0]['web_app']['url']);
        $this->assertSame($inviter->id, User::where('telegram_id', 501)->value('referred_by'));
    }

    public function test_links_are_built_from_the_config(): void
    {
        config(['neurogpt.telegram.webapp_url' => 'https://app.test', 'neurogpt.telegram.webapp_short_name' => 'app']);
        $this->assertSame('https://t.me/neuroai_gptbot/app?startapp=video-kling', Bot::directLink('video-kling'));
        $this->assertSame('https://app.test/', Bot::webAppUrl('bad value!'));

        config(['neurogpt.telegram.webapp_short_name' => '']);
        $this->assertSame('https://t.me/neuroai_gptbot?startapp=profile', Bot::directLink('profile'));
        $this->artisan('telegram:links')->assertSuccessful();
    }
}
