<?php

namespace App\Services\Generation;

use App\Exceptions\ContentFiltered;
use App\Exceptions\ProviderUnavailable;
use App\Jobs\NotifyGenerationFailure;
use App\Jobs\PollGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Admin\ProviderJournal;
use App\Services\Billing\Wallet;
use App\Services\Bot\BotSync;
use App\Services\Telegram\Bot;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * The life of one generation after it was paid for: run (or submit and poll), store the file,
 * send it to the chat, reward the inviter. Any failure refunds the tokens exactly once.
 */
class GenerationRunner
{
    public function __construct(
        private readonly ProviderManager $providers,
        private readonly PromptBuilder $prompts,
        private readonly Wallet $wallet,
        private readonly Bot $bot,
        private readonly BotSync $botSync,
        private readonly KieMusic $music,
    ) {}

    public function start(int $id, ?string $queue = null): void
    {
        // Claim the job: only a queued row moves on, so a duplicate delivery is harmless.
        $claimed = Generation::where('id', $id)->where('status', 'queued')
            ->update(['status' => 'processing', 'started_at' => now()]);
        if (! $claimed) {
            return;
        }

        $g = Generation::with(['model', 'user', 'photoset'])->findOrFail($id);

        try {
            if (! $g->model) {
                throw new ProviderUnavailable('The model was removed');
            }
            if (! isset($g->params['music'])) {
                $g->finalPrompt = $this->prompts->for($g);
            }
            $operation = $g->params['music']['operation'] ?? null;
            $provider = $this->providers->for($g->model);
            if (in_array($operation, ['lyrics', 'cover'], true)
                && (! $provider instanceof FakeProvider || config('neurogpt.providers.openai.api_key'))) {
                $this->complete($g, app(OpenAiProvider::class)->music($g, $operation));

                return;
            }
            $inputPath = $g->input_path ? Storage::disk('local')->path($g->input_path) : null;

            if ($provider instanceof AsyncProvider) {
                $submitted = $provider instanceof KieProvider && in_array($g->params['music']['operation'] ?? null, ['persona', 'boost_style'], true)
                    ? $provider->musicMetadata($g)
                    : $provider->submit($g, $g->model, $inputPath);
                if ($submitted instanceof MediaResult) {
                    $this->complete($g, $submitted);

                    return;
                }
                $g->update(['provider_task_id' => $submitted]);
                PollGeneration::dispatch($g->id)->onQueue($queue)->delay(now()->addSeconds(6));

                return;
            }

            $result = $provider->media($g, $g->model, $inputPath);
        } catch (Throwable $e) {
            $this->fail($g, $e);

            return;
        }

        $this->complete($g, $result);
    }

    /** One look at a submitted task. Returns true when the generation reached a final state. */
    public function poll(int $id): bool
    {
        $g = Generation::with(['model', 'user', 'photoset'])->find($id);
        if (! $g || $g->status !== 'processing' || ! $g->provider_task_id) {
            return true;
        }

        try {
            if (! $g->model) {
                throw new ProviderUnavailable('The model was removed');
            }
            $provider = $this->providers->for($g->model);
            if (! $provider instanceof AsyncProvider) {
                throw new ProviderUnavailable('The driver of this model was changed while the task was running');
            }
            $result = $provider->fetch($g, $g->model);
        } catch (Throwable $e) {
            $this->fail($g, $e);

            return true;
        }

        if ($result === null) {
            if ($g->started_at && $g->started_at->diffInSeconds(now()) > (int) config('neurogpt.limits.generation_timeout')) {
                $this->fail($g, new \RuntimeException('Generation timed out'));

                return true;
            }
            $g->touch(); // tells generations:reap that this task is still being watched

            return false;
        }

        $this->complete($g, $result);

        return true;
    }

    public function complete(Generation $g, MediaResult $result): void
    {
        if (isset($g->params['music'])) {
            $this->completeMusic($g, $result);

            return;
        }

        $path = 'generations/'.Str::uuid().'.'.$result->extension;
        Storage::disk('public')->put($path, $result->bytes);

        // Only a row that is still "processing" becomes "done": a job that was already failed and refunded stays failed.
        $won = Generation::where('id', $g->id)->where('status', 'processing')
            ->update(['status' => 'done', 'result_path' => $path, 'finished_at' => now()]);
        if (! $won) {
            Storage::disk('public')->delete($path);

            return;
        }

        $this->forgetInput($g);
        $this->logToBot($g, $path);

        try {
            $this->deliver($g, $result, $path);
        } catch (Throwable $e) {
            Log::warning('Result was not delivered to the chat', ['id' => $g->id, 'error' => Bot::scrub($e->getMessage())]);
        }
        $this->rewardReferrer($g->user);
    }

    private function completeMusic(Generation $g, MediaResult $result): void
    {
        $stored = [];
        $music = $result->music;
        try {
            foreach ($result->tracks as $track) {
                $audio = $this->music->audio($track['url'], $g);
                unset($track['url']);
                $track['path'] = $this->saveMusicFile($audio);
                $stored[] = $track['path'];
                $music['tracks'][] = $track;
            }
            if ($result->bytes !== '') {
                $stored[] = $path = $this->saveMusicFile($result);
                if (($g->params['music']['operation'] ?? null) !== 'cover') {
                    $music['tracks'][] = ['path' => $path, 'title' => $g->params['music']['name'] ?? $g->title()];
                }
            }
            $won = Generation::whereKey($g->id)->where('status', 'processing')->update([
                'status' => 'done', 'result_path' => $stored[0] ?? null, 'finished_at' => now(),
                'music_result' => json_encode($music, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            ]);
        } catch (Throwable $e) {
            Storage::disk('public')->delete($stored);
            $this->fail($g, $e);

            return;
        }
        if (! $won) {
            Storage::disk('public')->delete($stored);

            return;
        }

        $this->forgetInput($g);
        if (! empty($music['tracks'])) {
            $this->logToBot($g, $stored[0]);
            $this->rewardReferrer($g->user);
            if ($g->params['music']['auto_send']) {
                $this->sendTracks($g, $music['tracks']);
            }
        }
    }

    private function saveMusicFile(MediaResult $file): string
    {
        $path = 'generations/'.Str::uuid().'.'.$file->extension;
        if (! Storage::disk('public')->put($path, $file->bytes)) {
            throw new \RuntimeException('Cannot store music result');
        }

        return $path;
    }

    public function sendTracks(Generation $generation, array $tracks): bool
    {
        $chatId = $generation->user->telegram_id;
        $variants = [];
        foreach ($tracks as $index => $track) {
            $title = $track['title'] ?? $generation->params['music']['name'] ?? $generation->prompt;
            $title = Str::limit(trim((string) preg_replace('/\s+/u', ' ', (string) $title)), 90, '');
            $variants[$index] = $title !== '' ? $title : 'Без названия';
        }

        $lines = [];
        foreach ($variants as $index => $title) {
            $lines[] = 'Вариант '.($index + 1).' — «'.$title.'»';
        }
        $this->bot->sendMessage($chatId, "Ваше аудио готово!\n\n".implode("\n", $lines));

        $sent = true;
        foreach ($tracks as $index => $track) {
            try {
                $sent = $this->sendTrackFile($generation, $index, $track['path'], $variants[$index], $chatId) && $sent;
            } catch (Throwable $e) {
                Log::warning('Track was not delivered to Telegram', ['id' => $generation->id, 'error' => Bot::scrub($e->getMessage())]);
                $sent = false;
            }
        }

        return $sent;
    }

    private function sendTrackFile(Generation $generation, int $index, string $path, string $title, int $chatId): bool
    {
        $disk = Storage::disk('public');
        $caption = 'Вариант '.($index + 1).' — «'.$title.'»';
        if ($disk->size($path) > 48_000_000) {
            $url = URL::temporarySignedRoute('music.download', now()->addDay(), ['generation' => $generation->id, 'track' => $index]);

            return $this->bot->sendMessage($chatId, $caption."\n".$url) !== null;
        }
        $bytes = $disk->get($path);
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $safeTitle = trim((string) preg_replace('#[\\\/:*?"<>|\p{C}]#u', ' ', $title), " .");
        $filename = 'Вариант '.($index + 1).' — '.($safeTitle ?: 'Без названия').'.'.$ext;
        if (in_array($ext, ['mp3', 'm4a'], true) && $this->bot->sendAudio($chatId, $bytes, $filename, $caption, $title) !== null) {
            return true;
        }

        return $this->bot->sendDocument($chatId, $bytes, $filename, $caption) !== null;
    }

    public function fail(Generation $g, Throwable $e): void
    {
        $unavailable = $e instanceof ProviderUnavailable;
        $filtered = $e instanceof ContentFiltered || ($e instanceof RequestException
            && preg_match('/content_policy|safety|moderation|sensitive/i', (string) $e->response?->body()));

        Log::warning('Generation failed', [
            'id' => $g->id,
            'model' => $g->model?->slug,
            'driver' => $g->model?->driver,
            'task' => $g->provider_task_id,
            'operation' => $g->params['music']['operation'] ?? null,
            'error' => Bot::scrub($e->getMessage()),
        ]);

        $error = match (true) {
            $g->kind === 'song' && ProviderJournal::isCopyright($e->getMessage()) => 'copyright',
            $filtered => 'content_filtered',
            $unavailable => 'unavailable',
            $g->kind === 'song' && preg_match('/time.?out|timed out|no result in time/i', $e->getMessage()) => 'timeout',
            $g->kind === 'song' && $e instanceof \Illuminate\Http\Client\ConnectionException => 'connection',
            default => 'failed',
        };
        $refundReason = match ($error) {
            'copyright' => 'Ограничение по авторским правам',
            'content_filtered' => 'Запрос не прошёл фильтр',
            'unavailable' => 'Генерация пока недоступна',
            'timeout' => 'Истекло время ожидания',
            'connection' => 'Ошибка связи с провайдером',
            default => 'Модель перегружена',
        };
        $refunded = DB::transaction(function () use ($g, $error, $refundReason) {
            $locked = Generation::query()->whereKey($g->id)->lockForUpdate()->first();
            if (! $locked || ! $locked->isActive()) {
                return false;
            }
            $locked->update([
                'status' => 'failed', 'finished_at' => now(),
                'error' => $error,
            ]);
            if ($locked->cost > 0) {
                $this->wallet->refund(
                    User::findOrFail($locked->user_id), $locked->cost, 'Возврат',
                    ['generation_id' => $locked->id], $refundReason,
                    Wallet::spendOf($locked->id),
                );
            }

            return true;
        });

        if ($refunded) {
            $this->forgetInput($g);
            if (! $e instanceof RequestException && ! $e instanceof \Illuminate\Http\Client\ConnectionException) {
                ProviderJournal::failure((string) $g->model?->driver, 'generation_failed', $g->model, $g, $e);
            }
            try {
                NotifyGenerationFailure::dispatch($g->id, $error)->afterCommit();
            } catch (Throwable $notificationError) {
                Log::warning('Could not queue generation alert', ['id' => $g->id, 'exception' => $notificationError::class]);
            }
        }
    }

    /** The uploaded source is deleted once no other running generation needs it. */
    private function forgetInput(Generation $g): void
    {
        if (! $g->input_path) {
            return;
        }
        $shared = Generation::where('input_path', $g->input_path)->whereIn('status', ['queued', 'processing'])->exists();
        if (! $shared) {
            GenerationService::removeInput($g->input_path);
        }
    }

    private function deliver(Generation $g, MediaResult $result, string $path): void
    {
        $chatId = $g->user->telegram_id;
        $bytes = $result->bytes;
        $ext = $result->extension;
        $caption = Str::limit(trim($g->title().($g->prompt ? ': '.$g->prompt : '')), 900);
        $name = 'neurogpt-'.$g->id.'.'.$ext;

        // Telegram bots can upload at most 50 MB: a bigger file is sent as a link.
        if (strlen($bytes) > 48_000_000) {
            $this->bot->sendMessage($chatId, $caption."\n".Storage::disk('public')->url($path));

            return;
        }

        $sent = match (true) {
            in_array($ext, ['mp3', 'wav', 'ogg', 'm4a'], true) => $this->bot->sendAudio($chatId, $bytes, $name, $caption),
            in_array($ext, ['mp4', 'mov', 'webm'], true) => $this->bot->sendVideo($chatId, $bytes, $name, $caption),
            in_array($ext, ['jpg', 'jpeg', 'webp'], true) && strlen($bytes) < 9_500_000 => $this->bot->sendPhoto($chatId, $bytes, $name, $caption),
            // PNG goes as a file so that transparency and full quality survive.
            default => $this->bot->sendDocument($chatId, $bytes, $name, $caption),
        };

        // Telegram refuses some photos (odd dimensions, size): the file itself still gets there.
        if ($sent === null && ! in_array($ext, ['png', 'svg'], true)) {
            $this->bot->sendDocument($chatId, $bytes, $name, $caption);
        }
    }

    /** The finished request also goes to the bot table `query_logs`, so the bot's statistics include the app. */
    private function logToBot(Generation $g, string $path): void
    {
        $id = $this->botSync->logQuery(
            (int) $g->user_id,
            (string) ($g->model?->provider_model ?: $g->model?->name ?: $g->tool ?: $g->kind),
            $g->kind === 'tool' ? 'tool:'.$g->tool : ($g->photoset_id ? 'photoset' : $g->kind),
            (int) $g->cost,
            (string) ($g->prompt ?: $g->title()),
            Storage::disk('public')->url($path),
        );
        if ($id) {
            Generation::whereKey($g->id)->update(['query_log_id' => $id]);
        }
    }

    /**
     * The first finished generation of an invited person rewards both. Who invited is the bot's users.referal_user_from;
     * the app's own "already rewarded" mark is in wa_user_profiles (the bot's ref_paid is left to the bot).
     */
    private function rewardReferrer(User $user): void
    {
        if (! $user->referred_by || $user->referral_rewarded_at) {
            return;
        }

        $bonus = (int) config('neurogpt.bonus.referral');
        UserProfile::firstOrCreate(['user_id' => $user->id]);
        $first = UserProfile::whereKey($user->id)->whereNull('referral_rewarded_at')->update(['referral_rewarded_at' => now()]);
        $user->unsetRelation('profile');
        if (! $first || ! $bonus) {
            return;
        }

        $this->wallet->credit($user, $bonus, 'referral', 'Бонус за приглашение');

        // The friend always gets the gift; the inviter stops earning after the cap (protection from farming).
        $rewarded = UserProfile::whereNotNull('referral_rewarded_at')
            ->whereIn('user_id', User::where('referal_user_from', $user->referred_by)->select('id'))->count();
        $referrer = User::find($user->referred_by);
        if ($referrer && ! $referrer->is_blocked && $rewarded <= (int) config('neurogpt.limits.referral_rewards')) {
            $this->wallet->credit($referrer, $bonus, 'referral', 'Друг сделал первую генерацию', [], $user->displayName());
        }
    }
}
