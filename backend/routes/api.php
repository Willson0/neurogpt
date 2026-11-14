<?php

use App\Http\Controllers\Api\Admin\AnalyticsController;
use App\Http\Controllers\Api\Admin\CatalogController;
use App\Http\Controllers\Api\Admin\ContentController;
use App\Http\Controllers\Api\Admin\PaymentsController;
use App\Http\Controllers\Api\Admin\SettingsController;
use App\Http\Controllers\Api\Admin\UsersController;
use App\Http\Controllers\Api\BootstrapController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\GenerationController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\TelegramWebhookController;
use App\Http\Middleware\AdminOnly;
use App\Http\Middleware\TelegramAuth;
use App\Http\Middleware\YooKassaIp;
use Illuminate\Support\Facades\Route;

// Called by Telegram / YooKassa, no user session. Telegram is checked by its secret header, YooKassa by IP.
Route::post('telegram/webhook', TelegramWebhookController::class)->middleware('throttle:webhook');
Route::post('payments/yookassa/webhook', [PaymentController::class, 'webhook'])->middleware(['throttle:webhook', YooKassaIp::class]);

// What the app needs before sign-in: the bot for the browser login button and the color theme.
Route::get('config', fn () => response()->json([
    'bot_username' => config('neurogpt.telegram.bot_username'),
    'theme' => config('neurogpt.theme'),
]))->middleware('throttle:guard');

// throttle:guard counts by IP before the signature is checked, throttle:api counts by user after it.
Route::middleware(['throttle:guard', TelegramAuth::class, 'throttle:api'])->group(function () {
    Route::post('auth/telegram', [MeController::class, 'show']);
    Route::get('bootstrap', BootstrapController::class);
    Route::get('me', [MeController::class, 'show']);
    Route::get('transactions', [MeController::class, 'transactions']);
    Route::post('bonus/daily', [MeController::class, 'dailyBonus']);
    // Button presses and screen views for the admin analytics, sent in batches.
    Route::post('events', [EventController::class, 'store'])->middleware('throttle:events');

    Route::get('generations', [GenerationController::class, 'index']);
    Route::post('generations', [GenerationController::class, 'store'])->middleware('throttle:generate');
    Route::get('generations/{generation}', [GenerationController::class, 'show'])->whereNumber('generation');
    Route::patch('generations/{generation}', [GenerationController::class, 'update'])->whereNumber('generation');
    Route::post('generations/{generation}/cancel', [GenerationController::class, 'cancel'])->whereNumber('generation');
    Route::post('prompt/improve', [GenerationController::class, 'improve'])->middleware('throttle:improve');

    Route::get('chats', [ChatController::class, 'index']);
    Route::get('chats/{chat}', [ChatController::class, 'show'])->whereNumber('chat');
    Route::post('chats/messages', [ChatController::class, 'send'])->middleware('throttle:generate');

    Route::post('payments/topup', [PaymentController::class, 'topup'])->middleware('throttle:pay');
    Route::post('payments/plan', [PaymentController::class, 'plan'])->middleware('throttle:pay');
    Route::get('payments/{payment}', [PaymentController::class, 'show'])->whereNumber('payment');
});

// The admin panel. AdminOnly checks the Telegram id on the server for every route below.
Route::middleware(['throttle:guard', TelegramAuth::class, AdminOnly::class, 'throttle:api'])->prefix('admin')->group(function () {
    Route::get('stats', [UsersController::class, 'stats']);
    Route::get('analytics', [AnalyticsController::class, 'show']);

    Route::get('settings', [SettingsController::class, 'show']);
    Route::put('settings', [SettingsController::class, 'update']);

    Route::get('models', [CatalogController::class, 'models']);
    Route::post('models', [CatalogController::class, 'storeModel']);
    Route::patch('models/{model}', [CatalogController::class, 'updateModel'])->whereNumber('model');
    Route::delete('models/{model}', [CatalogController::class, 'destroyModel'])->whereNumber('model');
    // Pulls new bot models and prompts right away (the scheduler does the same every 5 minutes).
    Route::post('models/sync', [CatalogController::class, 'syncBot']);

    Route::get('tools', [CatalogController::class, 'tools']);
    Route::post('tools', [CatalogController::class, 'saveTool']);
    Route::put('tools/{slug}', [CatalogController::class, 'saveTool'])->where('slug', '[a-z0-9_]+');
    Route::delete('tools/{slug}', [CatalogController::class, 'destroyTool'])->where('slug', '[a-z0-9_]+');

    Route::get('photosets', [CatalogController::class, 'photosets']);
    Route::post('photosets', [CatalogController::class, 'savePhotoset']);
    Route::patch('photosets/{photoset}', [CatalogController::class, 'savePhotoset'])->whereNumber('photoset');
    Route::delete('photosets/{photoset}', [CatalogController::class, 'destroyPhotoset'])->whereNumber('photoset');
    Route::post('photosets/{photoset}/example', [CatalogController::class, 'photosetExample'])->whereNumber('photoset')->middleware('throttle:generate');
    Route::post('photosets/{photoset}/cover', [CatalogController::class, 'photosetCover'])->whereNumber('photoset');

    Route::get('news', [CatalogController::class, 'news']);
    Route::post('news', [CatalogController::class, 'saveNews']);
    Route::patch('news/{news}', [CatalogController::class, 'saveNews'])->whereNumber('news');
    Route::delete('news/{news}', [CatalogController::class, 'destroyNews'])->whereNumber('news');

    Route::post('upload', [CatalogController::class, 'upload']);

    Route::get('generations', [ContentController::class, 'generations']);
    Route::get('generations/{generation}/input', [ContentController::class, 'input'])->whereNumber('generation');
    Route::delete('generations/{generation}', [ContentController::class, 'destroyGeneration'])->whereNumber('generation');
    Route::get('messages', [ContentController::class, 'messages']);

    Route::get('users', [UsersController::class, 'index']);
    Route::patch('users/{user}', [UsersController::class, 'update'])->whereNumber('user');
    Route::post('users/{user}/balance', [UsersController::class, 'balance'])->whereNumber('user');
    Route::get('payments', [PaymentsController::class, 'index']);
    Route::post('payments/{payment}/sync', [PaymentsController::class, 'sync'])->whereNumber('payment');
    // Reading is open to every admin (the secret key is never returned); changing is checked inside for the main admin.
    Route::get('yookassa', [PaymentsController::class, 'yookassa']);
    Route::put('yookassa', [PaymentsController::class, 'saveYookassa']);
    Route::post('yookassa/check', [PaymentsController::class, 'checkYookassa']);

    // Reading is open to every admin; adding and removing is checked inside for the main admin.
    Route::get('admins', [UsersController::class, 'admins']);
    Route::post('admins', [UsersController::class, 'addAdmin']);
    Route::delete('admins/{telegramId}', [UsersController::class, 'removeAdmin'])->whereNumber('telegramId');
});
