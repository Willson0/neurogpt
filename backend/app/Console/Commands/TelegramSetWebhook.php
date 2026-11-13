<?php

namespace App\Console\Commands;

use App\Services\Telegram\Bot;
use Illuminate\Console\Command;

class TelegramSetWebhook extends Command
{
    protected $signature = 'telegram:set-webhook';

    protected $description = 'Point the bot webhook at this application';

    public function handle(Bot $bot): int
    {
        $secret = config('neurogpt.telegram.webhook_secret');
        if (! $secret) {
            $this->error('Set TELEGRAM_WEBHOOK_SECRET first.');

            return self::FAILURE;
        }

        $url = rtrim(config('app.url'), '/').'/api/telegram/webhook';
        if ($bot->setWebhook($url, $secret) === null) {
            $this->error('Telegram refused the request, check the log.');

            return self::FAILURE;
        }
        $bot->setCommands();
        $bot->setMenuButton('Открыть');
        $this->info("Webhook set: $url (updates: message, pre_checkout_query)");

        return self::SUCCESS;
    }
}
