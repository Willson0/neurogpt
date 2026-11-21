<?php

namespace App\Services\Generation;

use App\Exceptions\InsufficientBalance;
use App\Exceptions\ModelLocked;
use App\Exceptions\TooManyActive;
use App\Jobs\RunGeneration;
use App\Models\AiModel;
use App\Models\Generation;
use App\Models\Photoset;
use App\Models\User;
use App\Services\Billing\Wallet;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class GenerationService
{
    public function __construct(private readonly Wallet $wallet) {}

    /**
     * Charges the balance and queues the work.
     *
     * @param  array{kind:string, model?:?string, prompt?:?string, photoset_id?:?int, tool?:?string, option?:?string, format?:?string, duration?:?int, count?:?int, style?:?string, negative?:?string, source_generation_id?:?int}  $data
     * @param  bool  $free  nothing is charged (admin examples)
     * @return list<Generation>
     *
     * @throws InsufficientBalance|ModelLocked|TooManyActive|ValidationException
     */
    public function create(User $user, array $data, ?UploadedFile $file = null, bool $free = false): array
    {
        $kind = $data['kind'];
        $photoset = ! empty($data['photoset_id']) ? Photoset::active()->findOrFail($data['photoset_id']) : null;
        $tool = $kind === 'tool' ? $data['tool'] : null;
        $toolCfg = $tool ? config("neurogpt.tools.$tool") : null;

        $model = $this->model($user, $kind, $tool, $data['model'] ?? null);
        if (! $user->canUse($model)) {
            throw new ModelLocked($model->min_plan);
        }

        $prompt = trim((string) ($data['prompt'] ?? ''));
        $params = $this->params($kind, $model, $toolCfg, $data, $prompt);

        $count = $kind === 'image' && ! $photoset ? max(1, min(4, (int) ($data['count'] ?? 1))) : 1;
        $unit = match ($kind) {
            'tool' => (int) $toolCfg['cost'],
            'video' => (int) ceil($model->price * ($model->durations()[$params['duration']] ?? 1)),
            // Songs are priced per 30 seconds: 30 s = 1x, 60 s = 2x, 120 s = 4x.
            'song' => isset($data['music'])
                ? (int) config('neurogpt.music.prices.'.$data['music']['operation'], $model->price)
                : (int) ($model->price * max(1, intdiv((int) ($params['duration'] ?? 30), 30))),
            default => $model->price,
        };
        if ($free) {
            // An admin making an example picture for a photoset is not charged.
            $unit = 0;
        }

        $limit = (int) config('neurogpt.limits.active_generations');
        $active = Generation::where('user_id', $user->id)->whereIn('status', ['queued', 'processing'])->count();
        if ($active + $count > $limit) {
            throw new TooManyActive($limit);
        }
        // Fail before touching the disk when the balance is obviously short; the real check is inside the transaction.
        if ($user->balance < $unit * $count) {
            throw new InsufficientBalance($unit * $count, $user->balance);
        }

        $inputPath = isset($data['music'])
            ? $this->storeAudio($user, $file)
            : $this->storeInput($user, $file, $data['source_generation_id'] ?? null);

        try {
            $created = DB::transaction(function () use ($user, $kind, $model, $photoset, $tool, $prompt, $params, $inputPath, $unit, $count) {
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $limit = (int) config('neurogpt.limits.active_generations');
                $active = Generation::where('user_id', $user->id)->whereIn('status', ['queued', 'processing'])->count();
                if ($active + $count > $limit) {
                    throw new TooManyActive($limit);
                }
                $rows = [];
                for ($i = 0; $i < $count; $i++) {
                    $g = Generation::create([
                        'user_id' => $user->id, 'kind' => $kind, 'ai_model_id' => $model->id,
                        'photoset_id' => $photoset?->id, 'tool' => $tool, 'prompt' => $prompt,
                        'params' => $params, 'input_path' => $inputPath, 'cost' => $unit, 'status' => 'queued',
                    ]);
                    $g->setRelation('photoset', $photoset);
                    if ($unit > 0) {
                        $this->wallet->debit($user, $unit, $g->title(), ['generation_id' => $g->id], $model->name);
                    }
                    $rows[] = $g;
                }

                return $rows;
            });
        } catch (Throwable $e) {
            if ($inputPath) {
                Storage::disk('local')->delete($inputPath);
            }
            throw $e;
        }

        $queue = $user->hasActivePlan() ? 'high' : 'default';
        foreach ($created as $g) {
            RunGeneration::dispatch($g->id)->onQueue($queue);
        }

        return $created;
    }

    private function model(User $user, string $kind, ?string $tool, ?string $slug): AiModel
    {
        if ($kind === 'tool') {
            return AiModel::active()->where('kind', 'tool')->where('slug', $tool)->firstOrFail();
        }

        $list = AiModel::active()->where('kind', $kind)->get();

        if (! $slug || $slug === 'auto') {
            $preferred = match ($kind) {
                'image' => $user->photo_model_id,
                'video' => $user->video_model_id,
                default => null,
            };
            if ($preferred && ($model = $list->firstWhere('bot_model_id', $preferred)) && $user->canUse($model)) {
                return $model;
            }
        }

        $found = $list->firstWhere('slug', $slug ?: 'auto');
        if (! $found && $slug && $slug !== 'auto') {
            abort(404, 'Unknown model');
        }

        // Fall back to the web catalog when the bot's choice has no active web mapping.
        return $found ?? $list->firstOrFail();
    }

    /** Only values the chosen model really supports end up in params. */
    private function params(string $kind, AiModel $model, ?array $tool, array $data, string $prompt): array
    {
        $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message]);
        $params = [];

        if ($kind === 'image') {
            $params['format'] = $data['format'] ?? '1:1';
            $style = $data['style'] ?? 'any';
            $params['style'] = array_key_exists($style, config('neurogpt.styles')) ? $style : 'any';
            $params['negative'] = trim((string) ($data['negative'] ?? ''));
        }

        if ($kind === 'video') {
            $formats = $model->formats();
            $params['format'] = in_array($data['format'] ?? null, $formats, true) ? $data['format'] : $formats[0];
            $durations = array_keys($model->durations());
            $duration = (int) ($data['duration'] ?? $durations[0]);
            $params['duration'] = in_array($duration, $durations, true) ? $duration : $fail('duration', 'Эта модель не делает видео такой длины');
        }

        if ($kind === 'song') {
            if (isset($data['music'])) {
                return ['music' => $data['music']];
            }
            $params['duration'] = (int) ($data['duration'] ?? 30);
        }

        if ($kind === 'tool') {
            $input = $tool['input'] ?? 'none';
            if ($input === 'text_required' && $prompt === '') {
                $fail('prompt', 'Напишите, что нужно сделать');
            }
            if ($input === 'choice') {
                $option = (string) ($data['option'] ?? '');
                $params['option'] = isset($tool['options'][$option]) ? $option : $fail('option', 'Выберите вариант');
            }
            if (isset($tool['duration'])) {
                $params['duration'] = (int) $tool['duration'];
            }
        }

        return array_filter($params, fn ($v) => $v !== null && $v !== '');
    }

    private function storeAudio(User $user, ?UploadedFile $file): ?string
    {
        if (! $file) {
            return null;
        }
        $ext = match ($file->getMimeType()) {
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'audio/aac' => 'aac',
            'audio/ogg' => 'ogg',
            'audio/flac', 'audio/x-flac' => 'flac',
            'audio/webm', 'video/webm' => 'webm',
            default => 'wav',
        };
        $path = Storage::disk('local')->putFileAs('inputs/'.$user->id, $file, Str::uuid().'.'.$ext);
        if (! $path) {
            throw ValidationException::withMessages(['file' => 'Не удалось сохранить аудиофайл']);
        }

        return $path;
    }

    /** Saves the upload (re-encoded, metadata stripped) or copies the user's own finished picture. */
    private function storeInput(User $user, ?UploadedFile $file, ?int $sourceId): ?string
    {
        if ($file) {
            $image = ImageNormalizer::upload($file->getRealPath());
            if (! @getimagesizefromstring($image->bytes)) {
                throw ValidationException::withMessages(['file' => 'Не получилось прочитать фото, попробуйте другое']);
            }
            $path = 'inputs/'.$user->id.'/'.Str::uuid().'.'.$image->extension;
            Storage::disk('local')->put($path, $image->bytes);

            return $path;
        }

        if (! $sourceId) {
            return null;
        }

        // A tool can start from the user's own finished picture instead of an upload.
        $source = Generation::where('user_id', $user->id)->where('status', 'done')->whereIn('kind', ['image', 'tool'])
            ->whereNotNull('result_path')->find($sourceId);
        $public = Storage::disk('public');
        $ext = strtolower(pathinfo((string) $source?->result_path, PATHINFO_EXTENSION));
        if (! $source || ! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || ! $public->exists($source->result_path)) {
            throw ValidationException::withMessages(['source_generation_id' => 'Исходная картинка не найдена']);
        }
        $path = 'inputs/'.$user->id.'/'.Str::uuid().'.'.$ext;
        Storage::disk('local')->put($path, $public->get($source->result_path));

        return $path;
    }

    /** Only a queued job can be cancelled; once it runs the provider has been paid. */
    public function cancel(Generation $g): bool
    {
        $cancelled = DB::transaction(function () use ($g) {
            $locked = Generation::query()->whereKey($g->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'queued') {
                return false;
            }
            $locked->update(['status' => 'cancelled', 'finished_at' => now()]);
            $user = User::findOrFail($locked->user_id);
            if ($locked->cost > 0) {
                $this->wallet->refund($user, $locked->cost, 'Возврат', ['generation_id' => $locked->id], 'Отмена', Wallet::spendOf($locked->id));
            }

            return true;
        });

        if ($cancelled && isset($g->params['music']) && $g->input_path) {
            Storage::disk('local')->delete($g->input_path);
        }

        return $cancelled;
    }
}
