<?php

$rubPerStar = max(0.1, (float) env('TELEGRAM_STARS_RUB_RATE', 2.0));
$kieKey = env('KIE_API_KEY');
$default = env('GENERATION_DRIVER', 'fake');
// Tools and video run on kie.ai once a key is set; without one the local "fake" driver keeps development working.
$kieDriver = $kieKey ? 'kie' : ($default === 'fake' ? 'fake' : 'unavailable');

return [

    // Telegram ids that may open the admin panel. Checked on the server for every /api/admin request.
    'admin_ids' => [1337592809],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        // Stored without "@", whatever was typed into .env.
        'bot_username' => ltrim(trim((string) env('TELEGRAM_BOT_USERNAME', '')), '@'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        // Mini App link for the /start button, e.g. https://app.example.com
        'webapp_url' => env('TELEGRAM_WEBAPP_URL', env('APP_URL')),
        // How long initData is accepted after auth_date, in seconds.
        'init_data_ttl' => (int) env('TELEGRAM_INIT_DATA_TTL', 86400),
        // Browser sign-in for the admin (Telegram Login Widget): how long one sign-in stays valid, in seconds.
        'login_ttl' => (int) env('TELEGRAM_LOGIN_TTL', 30 * 86400),
        // Local development only: lets the SPA run in a plain browser.
        'allow_dev_auth' => (bool) env('TELEGRAM_ALLOW_DEV_AUTH', false),
        // Shown by /paysupport and in the profile ("Помощь"): https://t.me/your_support
        'support_url' => env('SUPPORT_URL'),
    ],

    // Telegram Stars (XTR). Prices are derived from the ruble price: stars = ceil(rub / rub_per_star).
    'stars' => [
        'enabled' => (bool) env('TELEGRAM_STARS_ENABLED', true),
        'rub_per_star' => $rubPerStar,
    ],

    'yookassa' => [
        'shop_id' => env('YOOKASSA_SHOP_ID'),
        'secret_key' => env('YOOKASSA_SECRET_KEY'),
        'api_url' => env('YOOKASSA_API_URL', 'https://api.yookassa.ru/v3'),
        'return_url' => env('YOOKASSA_RETURN_URL', env('APP_URL')),
        // Webhooks are accepted only from these networks (official YooKassa list).
        'verify_ip' => (bool) env('YOOKASSA_VERIFY_IP', true),
        'allowed_ips' => [
            '185.71.76.0/27', '185.71.77.0/27', '77.75.153.0/25', '77.75.156.11',
            '77.75.156.35', '77.75.154.128/25', '2a02:5180::/32',
        ],
        // 54-FZ receipt. Turn on when the shop has the receipt service enabled.
        'receipt' => [
            'enabled' => (bool) env('YOOKASSA_RECEIPT', false),
            'email' => env('YOOKASSA_RECEIPT_EMAIL'),
            'tax_system_code' => (int) env('YOOKASSA_TAX_SYSTEM', 1),
            'vat_code' => (int) env('YOOKASSA_VAT_CODE', 1),
        ],
    ],

    // Token packs (price in rubles; the Stars price is computed from it).
    'packs' => [
        ['tokens' => 100, 'price' => 100],
        ['tokens' => 500, 'price' => 450, 'badge' => 'Популярный'],
        ['tokens' => 1500, 'price' => 1200, 'badge' => 'Выгодно'],
        ['tokens' => 5000, 'price' => 3600, 'badge' => 'Максимум'],
    ],

    // Subscription plans: monthly price, price per month on a yearly plan, tokens per month.
    'plans' => [
        'free' => ['name' => 'Free', 'month' => 0, 'year_month' => 0, 'tokens' => 0],
        'plus' => ['name' => 'Plus', 'month' => 780, 'year_month' => 540, 'tokens' => 1000],
        'pro' => ['name' => 'Pro', 'month' => 1980, 'year_month' => 1380, 'tokens' => 3000],
    ],

    'bonus' => [
        'signup' => 50,
        'daily' => 5,
        'referral' => 50,
    ],

    'limits' => [
        // Generations one user may have queued or running at the same time.
        'active_generations' => (int) env('LIMIT_ACTIVE_GENERATIONS', 6),
        // Referral rewards one inviter can collect in total (stops farming with throwaway accounts).
        'referral_rewards' => (int) env('LIMIT_REFERRAL_REWARDS', 100),
        // Free "improve the prompt" calls per user per day.
        'improve_per_day' => (int) env('LIMIT_IMPROVE_PER_DAY', 30),
        // A generation still running after this many seconds is failed and refunded.
        'generation_timeout' => (int) env('GENERATION_TIMEOUT', 1200),
    ],

    'features' => [
        // The "Песня" tab is shown as "Coming soon" until this is true.
        'songs' => (bool) env('FEATURE_SONGS', false),
    ],

    /*
     | Image styles: the id travels from the app, the text goes into the provider prompt.
     */
    'styles' => [
        'any' => ['name' => 'Любой', 'prompt' => null],
        'photo' => ['name' => 'Фото', 'prompt' => 'photorealistic photograph, natural lighting, realistic textures, shallow depth of field, shot on a full-frame camera'],
        'cinema' => ['name' => 'Кино', 'prompt' => 'cinematic film still, dramatic lighting, anamorphic lens, rich color grading, subtle film grain'],
        'anime' => ['name' => 'Аниме', 'prompt' => 'anime illustration, clean line art, cel shading, expressive eyes, vibrant yet soft colors'],
        '3d' => ['name' => '3D', 'prompt' => 'stylized 3D render, soft global illumination, smooth materials, Pixar-like character design, high detail'],
        'watercolor' => ['name' => 'Акварель', 'prompt' => 'delicate watercolor painting, soft washes, visible paper texture, gentle color bleeding'],
        'oil' => ['name' => 'Масло', 'prompt' => 'classical oil painting, visible brush strokes, rich impasto texture, museum-quality lighting'],
        'comic' => ['name' => 'Комикс', 'prompt' => 'comic book art, bold ink outlines, halftone shading, dynamic composition, saturated colors'],
        'pixel' => ['name' => 'Пиксель-арт', 'prompt' => 'detailed pixel art, limited color palette, crisp pixels, retro video game aesthetic'],
        'neon' => ['name' => 'Неон', 'prompt' => 'neon cyberpunk aesthetic, glowing lights, night city reflections, magenta and cyan palette'],
    ],

    /*
     | Video models (kie.ai). `price` is in tokens for the first listed duration;
     | `durations` maps seconds to a price multiplier. `family` picks the request format in KieProvider.
     */
    'video' => [
        'seedance-mini' => [
            'name' => 'Seedance 2 Mini', 'hint' => 'Быстрый черновик со звуком, 480p', 'price' => 20, 'fast' => true,
            'min_plan' => 'free', 'provider_model' => 'bytedance/seedance-2-mini', 'family' => 'seedance',
            'durations' => [5 => 1, 10 => 2], 'formats' => ['16:9', '9:16', '1:1'], 'resolution' => '480p',
        ],
        'veo-lite' => [
            'name' => 'Veo 3.1 Lite', 'hint' => '8 секунд со звуком, 720p', 'price' => 40, 'badge' => 'Советуем', 'fast' => true,
            'min_plan' => 'free', 'provider_model' => 'veo-3-1', 'family' => 'veo', 'tier' => 'veo3_lite',
            'durations' => [8 => 1], 'formats' => ['16:9', '9:16'], 'resolution' => '720p',
        ],
        'veo-fast' => [
            'name' => 'Veo 3.1 Fast', 'hint' => 'Точнее движение и детали, 720p', 'price' => 80,
            'min_plan' => 'plus', 'provider_model' => 'veo-3-1', 'family' => 'veo', 'tier' => 'veo3_fast',
            'durations' => [8 => 1], 'formats' => ['16:9', '9:16'], 'resolution' => '720p',
        ],
        'kling' => [
            'name' => 'Kling 3.0', 'hint' => 'Живая камера и звук, до 10 секунд', 'price' => 110,
            'min_plan' => 'plus', 'provider_model' => 'kling-3.0/video', 'family' => 'kling', 'mode' => 'std',
            'durations' => [5 => 1, 10 => 2], 'formats' => ['16:9', '9:16', '1:1'],
        ],
        'veo' => [
            'name' => 'Veo 3.1 Quality', 'hint' => 'Максимальное качество, 1080p', 'price' => 250, 'badge' => 'Топ',
            'min_plan' => 'pro', 'provider_model' => 'veo-3-1', 'family' => 'veo', 'tier' => 'veo3',
            'durations' => [8 => 1], 'formats' => ['16:9', '9:16'], 'resolution' => '1080p',
        ],
    ],

    /*
     | Tools of the "Ещё" tab. Each one takes the user's photo.
     |   input   : none | text (optional comment) | text_required | choice (one of `options`)
     |   driver  : image (the image model edits the photo by `prompt`) | kie (a dedicated kie.ai model)
     |   prompt  : instruction for the image model; {input} is the user's text, {option} the chosen option prompt
     |   output  : png keeps transparency, otherwise the result is a JPEG
     */
    'tools' => [
        'upscale' => [
            'name' => 'Улучшить качество', 'hint' => 'Увеличит в 2 раза и добавит чёткости', 'cost' => 10, 'icon' => 'upscale', 'group' => 'fix',
            'input' => 'none', 'driver' => 'kie', 'provider_model' => 'topaz/image-upscale', 'family' => 'topaz',
            'prompt' => 'Upscale this image to a higher resolution. Recover fine detail and sharpness, remove compression artifacts and noise. Do not change the content, composition, colors or faces in any way.',
        ],
        'remove_bg' => [
            'name' => 'Убрать фон', 'hint' => 'Прозрачный PNG за пару секунд', 'cost' => 3, 'icon' => 'eraser', 'group' => 'fix',
            'input' => 'none', 'driver' => 'kie', 'provider_model' => 'recraft/remove-background', 'family' => 'recraft-bg', 'output' => 'png',
            'prompt' => 'Remove the background completely and keep only the main subject with clean, precise edges including hair and fine details. Output the subject on a fully transparent background. Do not alter the subject.',
        ],
        'replace_bg' => [
            'name' => 'Заменить фон', 'hint' => 'Опишите, где должен оказаться объект', 'cost' => 10, 'icon' => 'image', 'group' => 'fix',
            'input' => 'text_required', 'placeholder' => 'Например: пляж на закате', 'driver' => 'image',
            'prompt' => 'Replace only the background of this photo with: {input}. Keep the main subject exactly as it is: same face, body, pose, clothing and proportions. Match the lighting direction, color temperature and shadows of the subject to the new background so the result looks like a real photograph. Do not add text or watermarks.',
        ],
        'remove_object' => [
            'name' => 'Убрать лишнее', 'hint' => 'Люди на фоне, провода, надписи', 'cost' => 10, 'icon' => 'wand', 'group' => 'fix',
            'input' => 'text_required', 'placeholder' => 'Что убрать: человек слева, надпись', 'driver' => 'image',
            'prompt' => 'Remove the following from this photo: {input}. Fill the area naturally with the surrounding background so no trace, blur or smudge remains. Keep everything else in the image completely unchanged: composition, colors, lighting, faces and all other objects.',
        ],
        'retouch' => [
            'name' => 'Ретушь портрета', 'hint' => 'Чистая кожа и свет без эффекта маски', 'cost' => 10, 'icon' => 'user', 'group' => 'fix',
            'input' => 'none', 'driver' => 'image',
            'prompt' => 'Professionally retouch this portrait. Even out skin tone, reduce blemishes, redness and under-eye shadows while preserving natural skin texture and pores. Slightly improve lighting, contrast and eye clarity. Keep the person fully recognizable: do not change facial features, face shape, age, hair, makeup style, expression, pose, clothing or background. The result must look like a natural photo, not an airbrushed or AI-generated face.',
        ],
        'restore' => [
            'name' => 'Реставрация фото', 'hint' => 'Уберёт царапины и вернёт резкость', 'cost' => 12, 'icon' => 'history', 'group' => 'fix',
            'input' => 'none', 'driver' => 'image',
            'prompt' => 'Restore this old or damaged photograph. Remove scratches, dust, stains, creases, tears and noise. Recover sharpness and detail, fix faded contrast and exposure. Keep the people exactly who they are: preserve facial features, expressions, clothing, composition and the original era of the photo. If the photo is black and white, keep it black and white. Do not invent new objects.',
        ],
        'colorize' => [
            'name' => 'Раскрасить ч/б', 'hint' => 'Естественные цвета для старых снимков', 'cost' => 10, 'icon' => 'dna', 'group' => 'fix',
            'input' => 'none', 'driver' => 'image',
            'prompt' => 'Colorize this black and white photograph with realistic, historically plausible colors: natural skin tones, believable clothing, sky and vegetation colors. Keep every detail, face, texture and the composition exactly as in the original. Do not restyle, crop or redraw the image, only add color.',
        ],
        'stylize' => [
            'name' => 'Стилизация', 'hint' => 'Аниме, 3D-мультфильм, комикс и другие', 'cost' => 10, 'icon' => 'spark', 'group' => 'create',
            'input' => 'choice', 'driver' => 'image',
            'options' => [
                'anime' => ['name' => 'Аниме', 'prompt' => 'a high-quality anime illustration with clean line art, cel shading and soft vibrant colors'],
                '3d' => ['name' => '3D-мультфильм', 'prompt' => 'a stylized 3D animated movie character render in the style of modern Pixar films, soft lighting, smooth materials'],
                'comic' => ['name' => 'Комикс', 'prompt' => 'a comic book panel with bold ink outlines, halftone shading and saturated colors'],
                'watercolor' => ['name' => 'Акварель', 'prompt' => 'a delicate watercolor painting with soft washes and visible paper texture'],
                'oil' => ['name' => 'Масло', 'prompt' => 'a classical oil painting with visible brush strokes and rich warm lighting'],
                'pixel' => ['name' => 'Пиксель-арт', 'prompt' => 'detailed 32-bit pixel art with a limited color palette'],
                'clay' => ['name' => 'Пластилин', 'prompt' => 'a handmade claymation figure scene with visible clay texture and soft studio lighting'],
                'sketch' => ['name' => 'Карандаш', 'prompt' => 'a detailed graphite pencil sketch on textured paper with fine hatching'],
            ],
            'prompt' => 'Redraw this photo as {option}. Keep the same people, their recognizable facial features, hairstyle, pose, clothing and the overall composition. Apply the style consistently to the whole image including the background. No text, no watermarks, no extra people.',
        ],
        'sticker' => [
            'name' => 'Стикер из фото', 'hint' => 'Белая обводка, готово для стикерпака', 'cost' => 10, 'icon' => 'heart', 'group' => 'create',
            'input' => 'text', 'placeholder' => 'Эмоция или подпись (необязательно)', 'driver' => 'image',
            'prompt' => 'Turn the main subject of this photo into a cute die-cut sticker illustration: bold clean outlines, flat vibrant colors, slightly exaggerated friendly expression, thick white border around the whole subject, plain solid light-gray background, centered, no shadow on the background. Keep the subject recognizable. {input}',
            'input_prefix' => 'Additional wish from the user: ',
        ],
        'avatar' => [
            'name' => 'Деловой портрет', 'hint' => 'Студийное фото для резюме и соцсетей', 'cost' => 12, 'icon' => 'crown', 'group' => 'create',
            'input' => 'text', 'placeholder' => 'Одежда или фон (необязательно)', 'driver' => 'image',
            'prompt' => 'Create a professional studio headshot of the person in this photo. Keep the face fully recognizable: same facial features, face shape, skin tone, hair color and hairstyle, same age. Dress the person in smart business attire, shoulders-up framing, soft diffused studio lighting, neutral blurred background, sharp focus on the eyes, natural skin texture. Photorealistic, looks like a real photo taken by a professional photographer. {input}',
            'input_prefix' => 'Additional wish from the user: ',
        ],
        'product' => [
            'name' => 'Карточка товара', 'hint' => 'Предмет на чистом фоне с мягкой тенью', 'cost' => 10, 'icon' => 'frame', 'group' => 'create',
            'input' => 'text', 'placeholder' => 'Фон или настроение (необязательно)', 'driver' => 'image',
            'prompt' => 'Create a professional e-commerce product photo of the item in this picture. Keep the product itself exactly the same: shape, colors, logos, labels and proportions must not change. Place it centered on a clean seamless studio background with soft even lighting and a subtle natural shadow under it. Sharp focus, high detail, no text, no watermarks, no extra objects. {input}',
            'input_prefix' => 'Additional wish from the user: ',
        ],
        'animate' => [
            'name' => 'Оживить фото', 'hint' => 'Видео 8 секунд из одной картинки', 'cost' => 40, 'icon' => 'video', 'group' => 'create',
            'input' => 'text', 'placeholder' => 'Что должно происходить (необязательно)', 'driver' => 'kie', 'provider_model' => 'veo-3-1', 'family' => 'veo',
            'tier' => 'veo3_lite', 'resolution' => '720p', 'duration' => 8, 'output' => 'video',
            'prompt' => 'Bring this photo to life as a short realistic video. Keep the subject, faces and the scene exactly as in the image. Add subtle natural motion: gentle camera push-in, breathing, blinking, hair and clothes moving slightly in the wind, ambient movement in the background. Smooth, cinematic, no morphing, no new objects. {input}',
            'input_prefix' => 'Motion requested by the user: ',
        ],
    ],

    /*
     | Generation providers. A model row points to a driver by name.
     | openai     : any OpenAI-compatible API (images, text)
     | kie        : kie.ai jobs API (video, upscale, background removal, image-to-video)
     | replicate  : Replicate predictions API (legacy, songs)
     | unavailable: no provider yet, the job fails and tokens are refunded
     | fake       : placeholders for local development, no network
     */
    'providers' => [
        'openai' => [
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'api_key' => env('OPENAI_API_KEY'),
            'timeout' => (int) env('OPENAI_TIMEOUT', 180),
            // How a photo is sent for editing: "multipart" (OpenAI) or "image_url" (HydraAI: JSON field with a data URI)
            'image_edit' => env('OPENAI_IMAGE_EDIT', 'multipart'),
            // Image size style: "pixels" (1024x1024, OpenAI) or "ratio" (1:1, HydraAI)
            'image_size' => env('OPENAI_IMAGE_SIZE', 'pixels'),
            'text_model' => env('OPENAI_TEXT_MODEL', 'gpt-4o-mini'),
            'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-1'),
        ],
        'kie' => [
            'base_url' => env('KIE_BASE_URL', 'https://api.kie.ai'),
            'upload_url' => env('KIE_UPLOAD_URL', 'https://kieai.redpandaai.co/api/file-base64-upload'),
            'api_key' => $kieKey,
            // Translate prompts to English with the text model before sending them to video models.
            'translate' => (bool) env('KIE_TRANSLATE_PROMPT', true),
        ],
        'replicate' => [
            'base_url' => env('REPLICATE_BASE_URL', 'https://api.replicate.com/v1'),
            'api_token' => env('REPLICATE_API_TOKEN'),
            'timeout' => (int) env('REPLICATE_TIMEOUT', 600),
            'song_prompt_field' => env('REPLICATE_SONG_PROMPT_FIELD', 'prompt'),
            'song_duration_field' => env('REPLICATE_SONG_DURATION_FIELD', 'duration'),
        ],
        'default' => $default,
        // Driver of video models and kie tools: "kie" with a key, otherwise fake (local) or unavailable (production).
        'kie_driver' => $kieDriver,
        'song_model' => env('REPLICATE_SONG_MODEL'),
    ],

    'upload_max_kb' => 10240,

    // Color overrides set in the admin panel: ['dark' => ['--color-accent' => '#ff8a3d'], 'light' => [...]].
    'theme' => ['dark' => [], 'light' => []],
];
