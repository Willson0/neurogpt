<?php

namespace Database\Seeders;

use App\Models\AiModel;
use App\Models\News;
use App\Models\Photoset;
use App\Services\Bot\BotSync;
use Illuminate\Database\Seeder;

/**
 * Safe to run on every start.
 *
 * The catalog (models, photosets) is the bot tables `models` and `prompts`: the seeder writes them only on an empty
 * local database (no row in `models` at all), because models.py requires a text, photo and video model for every
 * user. On a real bot database nothing is added there; new bot rows only get their app meta (BotSync::pullCatalog).
 * News cards are the app's own (wa_news): refreshed from here unless the admin edited or deleted them.
 */
class DatabaseSeeder extends Seeder
{
    /** Creates or refreshes a news card unless the admin owns it by now. */
    private function sync(string $class, array $key, array $attrs, string $tag): void
    {
        if (in_array($tag, (array) config('neurogpt.deleted'), true)) {
            return;
        }
        $row = $class::where($key)->first();
        if ($row?->edited_at) {
            return;
        }
        $class::updateOrCreate($key, $attrs);
    }

    public function run(): void
    {
        if (AiModel::query()->count() === 0) {
            $this->fillEmptyBotCatalog();
        }

        // ---- news cards on the home screen ----
        $news = [
            ['Видео из текста и фото', 'Veo 3.1, Kling 3.0 и Seedance уже здесь', 'rocket', 'ember', 'video'],
            ['Позовите друга', 'Обоим по '.config('neurogpt.bonus.referral').' токенов', 'gift', 'gold', 'invite'],
            ['1 500 токенов выгоднее', 'Скидка 20% на большой пакет', 'moneybag', 'ink', 'topup'],
        ];
        foreach ($news as $i => [$title, $sub, $stk, $style, $action]) {
            $this->sync(News::class, ['title' => $title], ['subtitle' => $sub, 'sticker' => $stk, 'style' => $style, 'action' => $action, 'sort' => $i, 'is_active' => true], "news:$title");
        }
        News::whereNotIn('title', array_column($news, 0))->whereNull('edited_at')->update(['is_active' => false]);

        app(BotSync::class)->pullCatalog();
    }

    /** A fresh local database: the config catalog goes to the bot tables `models` and `prompts`. */
    private function fillEmptyBotCatalog(): void
    {
        // the provider is not stored: an empty driver follows .env (providers.default, KIE_API_KEY, the song model)
        $text = config('neurogpt.providers.openai.text_model');
        $image = config('neurogpt.providers.openai.image_model');

        // ---- text (the bot chat) and images ----
        $models = [
            'text' => [
                ['auto', 'Авто', 'Сама выберет лучшую модель', 1, 'Советуем', true, 'free', $text],
                ['fast', 'Быстрая', 'Короткие ответы за секунду', 1, null, true, 'free', $text],
                ['smart', 'Умная', 'Сложные задачи, код, анализ', 3, null, false, 'free', $text],
                ['long', 'Документы', 'Читает PDF и длинные тексты', 5, null, false, 'plus', $text],
            ],
            'image' => [
                ['auto', 'Авто', 'Хороший результат для любой идеи', 8, 'Советуем', true, 'free', $image],
                ['quick', 'Быстрая', 'Черновик, чтобы проверить идею', 4, null, true, 'free', $image],
                ['detail', 'Детальная', 'Мелкие детали и текст на картинке', 12, null, false, 'free', $image],
                ['photo', 'Фотореализм', 'Как настоящая фотография', 16, 'Plus', false, 'plus', $image],
            ],
        ];
        foreach ($models as $kind => $list) {
            foreach ($list as $i => [$slug, $name, $hint, $price, $badge, $fast, $min, $remote]) {
                $this->model($kind, $slug, $remote, [
                    'name' => $name, 'hint' => $hint, 'price' => $price, 'badge' => $badge, 'is_fast' => $fast,
                    'min_plan' => $min, 'sort' => $i, 'is_active' => true,
                ]);
            }
        }

        // ---- video: kie.ai ----
        $video = config('neurogpt.video');
        $sort = 0;
        foreach ($video as $slug => $m) {
            $this->model('video', $slug, $m['provider_model'], [
                'name' => $m['name'], 'hint' => $m['hint'], 'price' => $m['price'], 'badge' => $m['badge'] ?? null,
                'is_fast' => $m['fast'] ?? false, 'min_plan' => $m['min_plan'], 'sort' => $sort++, 'is_active' => true,
                'options' => array_intersect_key($m, array_flip(['family', 'tier', 'mode', 'resolution', 'durations', 'formats'])),
            ]);
        }

        // ---- songs: hidden behind "Coming soon" until FEATURE_SONGS=true and a music model is set ----
        $songModel = config('neurogpt.providers.song_model');
        foreach ([
            ['auto', 'Авто', 'Сама выберет лучшую модель', 30, 'Советуем', false, 'free'],
            ['quick', 'Быстрая', 'Набросок мелодии за минуту', 20, null, true, 'free'],
            ['studio', 'Студия', 'Богаче звук и аранжировка', 50, null, false, 'plus'],
        ] as $i => [$slug, $name, $hint, $price, $badge, $fast, $min]) {
            $this->model('song', $slug, $songModel ?: 'song-'.$slug, [
                'name' => $name, 'hint' => $hint, 'price' => $price, 'badge' => $badge, 'is_fast' => $fast, 'min_plan' => $min,
                'sort' => $i,
                'is_active' => (bool) config('neurogpt.features.songs'),
            ]);
        }

        if (Photoset::query()->count() === 0) {
            // ---- photosets: a ready idea applied to the user's own photo ----
            $keep = 'Use the attached photo as the only reference for the person. Keep the face fully recognizable: same facial features, face shape, skin tone, eye color, hair color and hairstyle, same apparent age and gender. One person in the frame unless the photo shows several. No text, no watermarks, no extra limbs or fingers.';
            $sets = [
                ['Аниме-аватар', 3, 3, 'Ваш портрет в стиле аниме: мягкий свет, пастельные тона и аккуратные контуры. Подойдёт для аватарки.',
                    "Redraw the person as a high-quality anime character portrait: clean line art, cel shading, large expressive eyes, soft pastel palette, gentle rim light, simple dreamy background with bokeh. Head-and-shoulders framing. $keep", 'Из вашего селфи'],
                ['Деловой портрет', 41, 4, 'Студийное фото в деловом стиле: для резюме, сайта и соцсетей.',
                    "Create a professional corporate headshot of the person: tailored dark blazer, soft diffused studio lighting, neutral gray gradient background, shoulders-up framing, sharp focus on the eyes, natural skin texture, confident friendly expression. Photorealistic, like a photo by a professional photographer. $keep", 'Для резюме'],
                ['3D-мультгерой', 52, 2, 'Вы как герой современного мультфильма: большие глаза, мягкий свет, объёмная картинка.',
                    "Turn the person into a stylized 3D animated movie character in the style of modern Pixar films: slightly enlarged eyes, smooth skin shading, detailed hair, warm cinematic lighting, colorful softly blurred background. Waist-up framing. $keep", 'Как в мультфильме'],
                ['Новогодняя открытка', 9, 1, 'Вы в заснеженном городе с тёплыми огнями. Открытка для сторис или поздравления.',
                    "Place the person into a cozy winter holiday scene: snowy evening street, warm glowing garlands and shop windows, soft falling snow, knitted scarf and warm coat, happy relaxed expression, cinematic shallow depth of field. Photorealistic. $keep", 'Для сторис'],
                ['Неон-портрет', 27, 0, 'Ночной город, неоновая подсветка и резкие контрасты. Для постеров и обложек.',
                    "Create a cinematic neon portrait of the person at night: rain-wet city street, magenta and cyan neon signs reflecting on the skin, strong rim light, moody contrast, shallow depth of field. Photorealistic. $keep", null],
                ['Киберпанк', 63, 0, 'Футуристический образ: технологичная одежда, голограммы и мегаполис будущего.',
                    "Reimagine the person as a cyberpunk character: futuristic techwear jacket, subtle glowing accents, holographic billboards and a dense futuristic megacity behind, teal and orange lighting, highly detailed digital art. $keep", null],
                ['Королевский портрет', 74, 4, 'Классическая живопись маслом: бархат, золото и мягкий свет старых мастеров.',
                    "Paint the person as a classical royal oil portrait in the style of the old masters: rich velvet and gold-embroidered clothing, dark warm background, soft Rembrandt lighting, visible brush strokes, museum quality. $keep", 'Как в музее'],
                ['Ретро-плёнка 90-х', 85, 1, 'Тёплый плёночный кадр: зерно, вспышка и атмосфера девяностых.',
                    "Restyle the photo as an authentic 1990s film snapshot of the person: warm faded colors, visible film grain, direct on-camera flash, slightly soft focus, period-appropriate casual clothing and interior. Photorealistic. $keep", null],
                ['Космонавт', 96, 3, 'Вы в скафандре на фоне Земли и звёзд. Эффектный кадр для аватарки.',
                    "Portray the person as an astronaut: detailed white space suit, helmet with an open clear visor showing the face, planet Earth and stars in the background, realistic rim light from the sun, cinematic composition. Photorealistic. $keep", null],
                ['Акварельный питомец', 33, 2, 'Ваш питомец в мягкой акварели на светлом фоне. Хорошо смотрится в альбоме.',
                    'Paint the animal from the attached photo as a delicate watercolor illustration: soft washes, visible paper texture, light airy background, gentle color bleeding around the edges. Keep the animal recognizable: same breed, coat color, markings and pose. No text, no watermarks.', 'Фото питомца'],
            ];
            foreach ($sets as $i => [$name, $seed, $pal, $desc, $prompt, $hint]) {
                Photoset::create([
                    'name' => $name, 'prompt' => $prompt, 'is_trending' => $hint !== null, 'sort' => $i,
                    'kind' => 'photo', 'seed' => $seed, 'palette' => $pal, 'description' => $desc, 'trend_hint' => $hint, 'is_active' => true,
                ]);
            }
        }
    }

    private function model(string $kind, string $slug, ?string $remote, array $attrs): void
    {
        AiModel::create(['kind' => $kind, 'slug' => $slug, 'bot_name' => (string) ($remote ?: $slug), 'can_image' => $kind !== 'text'] + $attrs);
    }
}
