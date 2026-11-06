<?php

return [

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        // Mini App link for the /start button, e.g. https://app.example.com
        'webapp_url' => env('TELEGRAM_WEBAPP_URL', env('APP_URL')),
        // How long initData is accepted after auth_date, in seconds.
        'init_data_ttl' => (int) env('TELEGRAM_INIT_DATA_TTL', 86400),
        // Local development only: lets the SPA run in a plain browser.
        'allow_dev_auth' => (bool) env('TELEGRAM_ALLOW_DEV_AUTH', false),
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

    // Token packs sold through YooKassa (price in rubles).
    'packs' => [
        ['tokens' => 100, 'price' => 100],
        ['tokens' => 500, 'price' => 450, 'badge' => 'Популярный'],
        ['tokens' => 1500, 'price' => 1200, 'badge' => 'Выгодно, −20%'],
        ['tokens' => 5000, 'price' => 3600],
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

    // Fixed price of auxiliary tools.
    'tools' => [
        'upscale' => ['name' => 'Улучшить качество', 'hint' => 'Увеличит фото в 4 раза', 'cost' => 4],
        'remove_bg' => ['name' => 'Убрать фон', 'hint' => 'Прозрачный PNG', 'cost' => 2],
        'animate' => ['name' => 'Оживить фото', 'hint' => 'Видео из одной картинки', 'cost' => 40],
    ],

    /*
     | Generation providers. A model row points to a driver by name.
     | openai    : any OpenAI-compatible API (OpenAI, OpenRouter, a self-hosted gateway)
     | replicate : Replicate predictions API (video, upscale, background removal)
     | fake      : placeholders for local development, no network
     */
    'providers' => [
        'openai' => [
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'api_key' => env('OPENAI_API_KEY'),
            'timeout' => (int) env('OPENAI_TIMEOUT', 120),
            // How a photo is sent for editing: "multipart" (OpenAI) or "image_url" (HydraAI: JSON field with a data URI)
            'image_edit' => env('OPENAI_IMAGE_EDIT', 'multipart'),
            'text_model' => env('OPENAI_TEXT_MODEL', 'gpt-4o-mini'),
            'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-1'),
        ],
        'replicate' => [
            'base_url' => env('REPLICATE_BASE_URL', 'https://api.replicate.com/v1'),
            'api_token' => env('REPLICATE_API_TOKEN'),
            'timeout' => (int) env('REPLICATE_TIMEOUT', 600),
        ],
        'default' => env('GENERATION_DRIVER', 'fake'),
    ],

    'upload_max_kb' => 10240,
];
