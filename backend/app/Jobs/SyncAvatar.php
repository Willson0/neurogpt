<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Telegram\Bot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/** Copies the Telegram profile photo to our own storage so the app never depends on t.me image hosting. */
class SyncAvatar implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $userId) {}

    public function handle(Bot $bot): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }

        $bytes = $bot->profilePhoto($user->telegram_id);
        if (! $bytes) {
            return;
        }

        $path = "avatars/{$user->telegram_id}.jpg";
        Storage::disk('public')->put($path, $bytes);
        $user->forceFill(['photo_url' => "/storage/{$path}?v=".time()])->save();
    }
}
