<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Models\User;
use App\Services\Billing\Wallet;
use App\Services\Generation\ProviderManager;
use App\Services\Telegram\Bot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class RunGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $generationId) {}

    public function handle(ProviderManager $providers, Bot $bot, Wallet $wallet): void
    {
        // Claim the job: only a queued row moves on, so a duplicate delivery is harmless.
        $claimed = Generation::where('id', $this->generationId)->where('status', 'queued')
            ->update(['status' => 'processing', 'started_at' => now()]);
        if (! $claimed) {
            return;
        }

        $g = Generation::with(['model', 'user'])->findOrFail($this->generationId);

        try {
            $inputPath = $g->input_path ? Storage::disk('local')->path($g->input_path) : null;
            $result = $providers->for($g->model)->media($g, $g->model, $inputPath);

            $path = 'generations/'.Str::uuid().'.'.$result->extension;
            Storage::disk('public')->put($path, $result->bytes);
            $g->update(['status' => 'done', 'result_path' => $path, 'finished_at' => now()]);
        } catch (Throwable $e) {
            $this->fail($g, $e);

            return;
        }

        $this->deliver($g, $result->bytes, $result->extension, $bot);
        $this->rewardReferrer($g->user, $wallet);
    }

    public function failed(Throwable $e): void
    {
        // Worker killed or timed out: the row is still "processing".
        $g = Generation::find($this->generationId);
        if ($g && $g->status === 'processing') {
            $this->fail($g, $e);
        }
    }

    private function fail(Generation $g, Throwable $e): void
    {
        $filtered = $e instanceof RequestException
            && preg_match('/content_policy|safety|moderation/i', (string) $e->response?->body());

        Log::warning('Generation failed', ['id' => $g->id, 'error' => $e->getMessage()]);

        DB::transaction(function () use ($g, $filtered) {
            $locked = Generation::query()->whereKey($g->id)->lockForUpdate()->first();
            if (! $locked || $locked->status === 'failed') {
                return;
            }
            $locked->update(['status' => 'failed', 'error' => $filtered ? 'content_filtered' : 'failed', 'finished_at' => now()]);
            app(Wallet::class)->credit(
                User::findOrFail($locked->user_id), $locked->cost, 'refund', 'Возврат',
                ['generation_id' => $locked->id], $filtered ? 'Запрос не прошёл фильтр' : 'Модель перегружена',
            );
        });
    }

    private function deliver(Generation $g, string $bytes, string $ext, Bot $bot): void
    {
        $chatId = $g->user->telegram_id;
        $caption = $g->prompt ? Str::limit($g->prompt, 900) : null;
        $name = 'result.'.$ext;

        match (true) {
            in_array($ext, ['mp4', 'mov', 'webm'], true) => $bot->sendVideo($chatId, $bytes, $name, $caption),
            in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true) && strlen($bytes) < 9_500_000 => $bot->sendPhoto($chatId, $bytes, $name, $caption),
            default => $bot->sendDocument($chatId, $bytes, $name, $caption),
        };
    }

    private function rewardReferrer(User $user, Wallet $wallet): void
    {
        if (! $user->referred_by || $user->referral_rewarded_at) {
            return;
        }

        $bonus = (int) config('neurogpt.bonus.referral');
        $first = User::where('id', $user->id)->whereNull('referral_rewarded_at')->update(['referral_rewarded_at' => now()]);
        if (! $first || ! $bonus) {
            return;
        }

        $wallet->credit($user, $bonus, 'referral', 'Бонус за приглашение');
        if ($referrer = User::find($user->referred_by)) {
            $wallet->credit($referrer, $bonus, 'referral', 'Друг сделал первую генерацию', [], $user->displayName());
        }
    }
}
