<?php

namespace App\Console\Commands;

use App\Models\AiModel;
use App\Models\Photoset;
use App\Services\Payments\Shop;
use App\Services\Telegram\Bot;
use Illuminate\Console\Command;

/** Prints ready-made links that open the Mini App on a given screen, for the bot developer and for posts. */
class TelegramLinks extends Command
{
    protected $signature = 'telegram:links';

    protected $description = 'List links that open the Mini App on a given screen';

    public function handle(): int
    {
        $screens = [
            ['home', 'Главная'], ['gallery', 'Фотосеты'], ['profile', 'Профиль'], ['history', 'История операций'],
            ['image', 'Фото'], ['video', 'Видео'], ['tools', 'Ещё (инструменты)'], ['models', 'Выбор модели'],
            ['invite', 'Пригласить друга'], ['topup', 'Пополнить токены'], ['plans', 'Подписки'],
            ['plans-plus', 'Подписка Plus'], ['plans-pro', 'Подписка Pro'], ['plans-pro-year', 'Pro на год'],
            ['admin', 'Админка'], ['admin-payments', 'Админка: платежи'],
        ];
        foreach (['image' => 'Фото: ', 'video' => 'Видео: '] as $kind => $label) {
            foreach (AiModel::active()->where('kind', $kind)->get() as $m) {
                $screens[] = ["{$kind}-{$m->slug}", $label.$m->name];
            }
        }
        foreach (AiModel::active()->where('kind', 'tool')->get() as $t) {
            $screens[] = ["tool-{$t->slug}", 'Инструмент: '.$t->name];
        }
        // the payment screen of every row of the bot's shop: pay-<shop.id> (buy-<shop.id> = the pack/plan screen with it chosen)
        $items = app(Shop::class)->tokenItems()->concat(app(Shop::class)->subItems());
        foreach ($items as $i) {
            $screens[] = ["pay-{$i->id}", "Оплата: {$i->text_name} ({$i->price} ₽)"];
        }
        foreach (Photoset::where('is_active', true)->orderBy('sort')->get() as $p) {
            $screens[] = ["photoset-{$p->id}", 'Фотосет: '.$p->name];
        }

        $bot = config('neurogpt.telegram.bot_username');
        $this->line('web_app кнопка: '.Bot::webAppUrl().'?startapp=<экран>');
        $direct = Bot::directLink('home');
        $this->line('прямая ссылка: '.($direct ? str_replace('=home', '=<экран>', $direct) : 'задайте TELEGRAM_BOT_USERNAME'));
        $this->line($bot ? "через /start:   https://t.me/{$bot}?start=<экран>" : '');
        $this->newLine();
        $this->table(['Экран', 'Что откроется', 'URL для web_app кнопки', 'Прямая ссылка t.me'],
            array_map(fn ($s) => [$s[0], $s[1], Bot::webAppUrl($s[0]), Bot::directLink($s[0]) ?? '—'], $screens));
        if ($items->isNotEmpty()) {
            $this->newLine();
            $this->line('Ссылки на оплату (обычный GET-адрес, страница оплаты с товаром):');
            $this->table(['shop.id', 'Товар', 'Ссылка'], $items->map(fn ($i) => [$i->id, $i->text_name, Bot::payLink($i->id)])->all());
        }

        return self::SUCCESS;
    }
}
