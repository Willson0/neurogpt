<?php

namespace Database\Seeders;

use App\Models\AiModel;
use App\Models\News;
use App\Models\Photoset;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $driver = config('neurogpt.providers.default');

        // provider_model is the id on the provider's side; replace it with the models you plug in.
        $text = config('neurogpt.providers.openai.text_model');
        $image = config('neurogpt.providers.openai.image_model');
        $models = [
            'text' => [
                ['auto', 'Авто', 'Сама выберет лучшую модель', 1, 'Советуем', true, 'free', $text],
                ['fast', 'Быстрая', 'Короткие ответы за секунду', 1, 'Бесплатно', true, 'free', $text],
                ['smart', 'Умная', 'Сложные задачи, код, анализ', 3, null, false, 'free', $text],
                ['long', 'Документы', 'Читает PDF и длинные тексты', 5, null, false, 'plus', $text],
            ],
            'image' => [
                ['auto', 'Авто', 'Сама выберет лучшую модель', 8, 'Советуем', true, 'free', $image],
                ['quick', 'Быстрая', 'Черновик за 5 секунд', 4, null, true, 'free', $image],
                ['detail', 'Детальная', 'Мелкие детали и текст на картинке', 12, 'New', false, 'free', $image],
                ['photo', 'Фотореализм', 'Как настоящая фотография', 16, null, false, 'plus', $image],
            ],
            'video' => [
                ['auto', 'Авто', 'Сама выберет лучшую модель', 60, 'Советуем', false, 'pro', null],
                ['clip', 'Клип', 'Короткие ролики для сторис', 40, null, true, 'pro', null],
                ['cinema', 'Кино', 'Плавная камера, высокое качество', 120, null, false, 'pro', null],
            ],
        ];

        foreach ($models as $kind => $list) {
            foreach ($list as $i => [$slug, $name, $hint, $price, $badge, $fast, $min, $remote]) {
                AiModel::updateOrCreate(['kind' => $kind, 'slug' => $slug], [
                    'name' => $name, 'hint' => $hint, 'price' => $price, 'badge' => $badge, 'is_fast' => $fast,
                    'min_plan' => $min, 'driver' => $driver, 'provider_model' => $remote, 'sort' => $i,
                ]);
            }
        }

        foreach (array_keys(config('neurogpt.tools')) as $tool) {
            AiModel::updateOrCreate(['kind' => 'tool', 'slug' => $tool], [
                'name' => config("neurogpt.tools.$tool.name"), 'price' => config("neurogpt.tools.$tool.cost"),
                'driver' => $driver, 'is_active' => false,
            ]);
        }

        $sets = [
            ['Аниме-аватар', 3, 3, 'Ваш портрет в стиле аниме: мягкий свет, пастельные тона и аккуратные контуры. Подойдёт для аватарки.', 'Портрет в стиле аниме, мягкий свет, пастельные тона', 'Из вашего селфи'],
            ['Новогодняя открытка', 9, 1, 'Заснеженный город и тёплые огни в окнах. Открытка для сторис или поздравления в чат.', 'Новогодняя открытка: заснеженный город, тёплые огни', 'Для сторис'],
            ['Стикер с котом', 14, 2, 'Довольный кот с белой обводкой и яркими цветами. Удобно для стикерпака.', 'Стикер: довольный кот, белая обводка, яркие цвета', 'Белая обводка'],
            ['Карточка товара', 21, 4, 'Предмет на чистом фоне с мягкой тенью. Подходит для магазина и маркетплейса.', 'Карточка товара на чистом фоне, мягкая тень', 'Чистый фон'],
            ['Неон-портрет', 27, 0, 'Ночной город, неоновая подсветка и резкие контрасты. Для постеров и обложек.', 'Неон-портрет, ночной город, резкие контрасты', null],
            ['Акварельный питомец', 33, 2, 'Ваш питомец в мягкой акварели на светлом фоне. Хорошо смотрится в альбоме.', 'Питомец в акварели, светлый фон, мягкие мазки', null],
        ];
        foreach ($sets as $i => [$name, $seed, $pal, $desc, $prompt, $hint]) {
            Photoset::updateOrCreate(['name' => $name], [
                'seed' => $seed, 'palette' => $pal, 'description' => $desc, 'prompt' => $prompt,
                'is_trending' => $hint !== null, 'trend_hint' => $hint, 'sort' => $i,
            ]);
        }

        $news = [
            ['−20% на 1 500 токенов', 'Только до воскресенья', 'moneybag', 'ember', 'topup'],
            ['Модель «Детальная»', 'Пишет текст прямо на картинке', 'robot', 'ink', 'models'],
            ['Позовите друга', 'Обоим по 50 токенов', 'gift', 'gold', 'invite'],
        ];
        foreach ($news as $i => [$title, $sub, $stk, $style, $action]) {
            News::updateOrCreate(['title' => $title], ['subtitle' => $sub, 'sticker' => $stk, 'style' => $style, 'action' => $action, 'sort' => $i]);
        }
    }
}
