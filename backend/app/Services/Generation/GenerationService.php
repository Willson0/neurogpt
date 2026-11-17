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
        if ($photoset && $kind !== $photoset->generationKind()) {
            throw ValidationException::withMessages(['kind' => $photoset->isVideo() ? 'Видеосет создаёт видео' : 'Фотосет создаёт картинку']);
        }
        $tool = $kind === 'tool' ? $data['tool'] : null;
        $toolCfg = $tool ? config("neurogpt.tools.$tool") : null;

        $model = $this->model($kind, $tool, $data['model'] ?? null);
        if ($photoset && ($recommended = $photoset->recommendedModel())) {
            // As in the bot: a set runs on its recommended model (prompts.model) when the user may use it and has
            // enough tokens for it; otherwise on the user's own model. The price is that model's price in `models`.
            if ($user->canUse($recommended) && $user->balance >= self::unitPrice($recommended)) {
                $model = $recommended;
            }
            if ($kind === 'video') {
                // a set video is the shortest one of whichever model runs it
                $data['duration'] = array_key_first($model->durations());
            }
        }
        if (! $user->canUse($model)) {
            throw new ModelLocked($model->min_plan);
        }

        $prompt = trim((string) ($data['prompt'] ?? ''));
        $params = $this->params($kind, $model, $toolCfg, $data, $prompt);

        $count = $kind === 'image' && ! $photoset ? max(1, min(4, (int) ($data['count'] ?? 1))) : 1;
        $unit = match ($kind) {
            'tool' => Tools::cost($tool),
            'video' => (int) ceil($model->price * ($model->durations()[$params['duration']] ?? 1)),
            // Songs are priced per 30 seconds: 30 s = 1x, 60 s = 2x, 120 s = 4x.
            'song' => (int) ($model->price * max(1, intdiv((int) ($params['duration'] ?? 30), 30))),
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

        $inputPath = $this->storeInput($user, $file, $data['source_generation_id'] ?? null);

        try {
            $created = DB::transaction(function () use ($user, $kind, $model, $photoset, $tool, $prompt, $params, $inputPath, $unit, $count) {
                $rows = [];
                for ($i = 0; $i < $count; $i++) {
                    $g = Generation::create([
                        'user_id' => $user->id, 'kind' => $kind, 'ai_model_id' => $model->exists ? $model->id : null,
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

        // Pro subscribers go through their own queue first ("priority in the queue").
        $queue = $user->activePlan() === 'pro' ? 'high' : 'default';
        foreach ($created as $g) {
            RunGeneration::dispatch($g->id)->onQueue($queue);
        }

        return $created;
    }

    /** One run of a model: its price in the bot table `models` (a video model: the shortest video). */
    public static function unitPrice(AiModel $model): int
    {
        $durations = $model->durations();

        return (int) ceil($model->price * ($durations ? reset($durations) : 1));
    }

    private function model(string $kind, ?string $tool, ?string $slug): AiModel
    {
        if ($kind === 'tool') {
            abort_unless($tool && Tools::isActive($tool), 404, 'Unknown tool');

            return Tools::model($tool);
        }

        $list = AiModel::active()->ofKind($kind)->get();

        $found = $list->firstWhere('slug', $slug ?: 'auto');
        if (! $found && $slug && $slug !== 'auto') {
            abort(404, 'Unknown model');
        }

        // "auto" (or nothing) means the first model of the catalog.
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
        return DB::transaction(function () use ($g) {
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
    }
}
