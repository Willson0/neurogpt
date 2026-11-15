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
 * The catalog is the bot tables `models` and `prompts`; new rows there get their app settings (link id, provider,
 * photo or video set). Prompt examples stored as a Telegram file_id are downloaded once and become the cover in the app.
 * Runs every 5 minutes from the scheduler and on every start (db:seed).
 */
class SyncBot extends Command
{
    protected $signature = 'neurogpt:sync-bot';

    protected $description = 'Give new bot models and prompts their app settings, copy Telegram covers';

    public function handle(BotSync $sync, Bot $bot): int
    {
        $created = $sync->pullCatalog();
        $this->info("New in the bot tables: {$created['models']} models, {$created['photosets']} sets.");

        $covers = 0;
        if (config('neurogpt.telegram.bot_token')) {
            foreach (Photoset::whereNotNull('photo_example')->get()->filter(fn (Photoset $s) => ! $s->ownCover()) as $set) {
                $example = (string) $set->photo_example;
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
                $set->fill(['cover_url' => '/storage/'.$path])->save();
                $covers++;
            }
        }
        $this->info("Covers copied from Telegram: $covers.");

        return self::SUCCESS;
    }
}
