<?php

use App\Http\Controllers\Api\BootstrapController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\GenerationController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\TelegramWebhookController;
use App\Http\Middleware\TelegramAuth;
use App\Http\Middleware\YooKassaIp;
use Illuminate\Support\Facades\Route;

// Called by Telegram / YooKassa, no user session. Telegram is checked by its secret header, YooKassa by IP.
Route::post('telegram/webhook', TelegramWebhookController::class)->middleware('throttle:webhook');
Route::post('payments/yookassa/webhook', [PaymentController::class, 'webhook'])->middleware(['throttle:webhook', YooKassaIp::class]);

// throttle:guard counts by IP before the signature is checked, throttle:api counts by user after it.
Route::middleware(['throttle:guard', TelegramAuth::class, 'throttle:api'])->group(function () {
    Route::post('auth/telegram', [MeController::class, 'show']);
    Route::get('bootstrap', BootstrapController::class);
    Route::get('me', [MeController::class, 'show']);
    Route::get('transactions', [MeController::class, 'transactions']);
    Route::post('bonus/daily', [MeController::class, 'dailyBonus']);

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
