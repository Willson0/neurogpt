<?php

namespace App\Services\Generation;

use App\Exceptions\InsufficientBalance;
use App\Exceptions\ModelLocked;
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

class GenerationService
{
    public function __construct(private readonly Wallet $wallet) {}

    /**
     * Charges the balance and queues the work.
     *
     * @param  array{kind:string, model?:?string, prompt?:?string, photoset_id?:?int, tool?:?string, format?:?string, duration?:?int, count?:?int, style?:?string, negative?:?string, seed?:?int}  $data
     * @return list<Generation>
     *
     * @throws InsufficientBalance
     */
    public function create(User $user, array $data, ?UploadedFile $file = null): array
    {
        $kind = $data['kind'];
        $photoset = ! empty($data['photoset_id']) ? Photoset::active()->findOrFail($data['photoset_id']) : null;
        $tool = $kind === 'tool' ? $data['tool'] : null;

        $model = match (true) {
            $kind === 'tool' => AiModel::where('kind', 'tool')->where('slug', $tool)->firstOrFail(),
            default => AiModel::active()->where('kind', $kind)->where('slug', $data['model'] ?? 'auto')->firstOrFail(),
        };

        if (! $user->canUse($model)) {
            throw new ModelLocked($model->min_plan);
        }

        $count = $kind === 'image' && ! $photoset ? max(1, min(4, (int) ($data['count'] ?? 1))) : 1;
        $unit = match (true) {
            $kind === 'tool' => (int) config("neurogpt.tools.$tool.cost"),
            $kind === 'video' => $model->price * (((int) ($data['duration'] ?? 5)) === 10 ? 2 : 1),
            // Songs are priced per 30 seconds: 30 s = 1x, 60 s = 2x, 120 s = 4x.
            $kind === 'song' => $model->price * (max(30, (int) ($data['duration'] ?? 30)) / 30),
            default => $model->price,
        };

        $prompt = trim((string) ($data['prompt'] ?? ''));
        if ($photoset) {
            $prompt = $prompt !== '' ? $photoset->prompt.', '.$prompt : $photoset->prompt;
        }

        $inputPath = $file ? $file->storeAs('inputs/'.$user->id, Str::uuid().'.'.($file->guessExtension() ?: 'bin'), 'local') : null;

        // A tool can start from the user's own finished picture instead of an upload.
        if (! $inputPath && ! empty($data['source_generation_id'])) {
            $source = Generation::where('user_id', $user->id)->where('status', 'done')->where('kind', 'image')
                ->whereNotNull('result_path')->find($data['source_generation_id']);
            $public = Storage::disk('public');
            if (! $source || ! $public->exists($source->result_path)) {
                throw ValidationException::withMessages(['source_generation_id' => 'Исходная картинка не найдена']);
            }
            $inputPath = 'inputs/'.$user->id.'/'.Str::uuid().'.'.pathinfo($source->result_path, PATHINFO_EXTENSION);
            Storage::disk('local')->put($inputPath, $public->get($source->result_path));
        }

        $params = array_filter([
            'format' => $data['format'] ?? null,
            'duration' => $data['duration'] ?? null,
            'style' => $data['style'] ?? null,
            'negative' => $data['negative'] ?? null,
            'seed' => $data['seed'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $created = DB::transaction(function () use ($user, $kind, $model, $photoset, $tool, $prompt, $params, $inputPath, $unit, $count) {
            $rows = [];
            for ($i = 0; $i < $count; $i++) {
                $g = Generation::create([
                    'user_id' => $user->id, 'kind' => $kind, 'ai_model_id' => $model->id,
                    'photoset_id' => $photoset?->id, 'tool' => $tool, 'prompt' => $prompt,
                    'params' => $params, 'input_path' => $inputPath, 'cost' => $unit, 'status' => 'queued',
                ]);
                $this->wallet->debit($user, $unit, $this->title($g, $photoset), ['generation_id' => $g->id], $model->name);
                $rows[] = $g;
            }

            return $rows;
        });

        foreach ($created as $g) {
            RunGeneration::dispatch($g->id);
        }

        return $created;
    }

    private function title(Generation $g, ?Photoset $photoset): string
    {
        return match (true) {
            $photoset !== null => 'Фотосет «'.$photoset->name.'»',
            $g->kind === 'video' => 'Видео',
            $g->kind === 'song' => 'Песня',
            $g->kind === 'tool' => (string) config("neurogpt.tools.{$g->tool}.name"),
            default => 'Картинка',
        };
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
            $this->wallet->credit($user, $locked->cost, 'refund', 'Возврат', ['generation_id' => $locked->id], 'Отмена');

            return true;
        });
    }
}
