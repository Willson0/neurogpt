<?php

namespace App\Console\Commands;

use App\Models\Photoset;
use App\Services\Bot\BotSync;
use App\Services\Generation\ImageNormalizer;
use App\Services\Telegram\Bot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pulls the bot catalog into the app: new bot models and prompts appear in the app, linked rows refresh their
 * local copy. Prompt examples stored as a Telegram file_id are downloaded once and become the photoset cover.
 * Runs every 5 minutes from the scheduler and on every start (db:seed).
 */
class SyncBot extends Command
{
    protected $signature = 'neurogpt:sync-bot';

    protected $description = 'Bring new bot models and prompts into the app catalog';

    public function handle(BotSync $sync, Bot $bot): int
    {
        $created = $sync->pullCatalog();
        $this->info("New from the bot: {$created['models']} models, {$created['photosets']} photosets.");

        $covers = 0;
        if (config('neurogpt.telegram.bot_token')) {
            foreach (Photoset::whereNotNull('prompt_id')->whereNull('cover_url')->get() as $set) {
                $example = (string) $set->botPrompt?->photo_example;
                if ($example === '' || str_starts_with($example, 'http')) {
                    continue;
                }
                try {
                    $bytes = $bot->download($example);
                    $image = $bytes ? ImageNormalizer::normalize($bytes, 1080) : null;
                } catch (Throwable) {
                    $image = null;
                }
                if (! $image || ! @getimagesizefromstring($image->bytes)) {
                    continue;
                }
                $path = 'covers/'.Str::uuid().'.'.$image->extension;
                Storage::disk('public')->put($path, $image->bytes);
                $set->forceFill(['cover_url' => '/storage/'.$path])->saveQuietly();
                $covers++;
            }
        }
        $this->info("Covers copied from Telegram: $covers.");

        return self::SUCCESS;
    }
}
