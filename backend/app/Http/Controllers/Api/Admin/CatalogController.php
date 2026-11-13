<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Exceptions\TooManyActive;
use App\Http\Resources\GenerationResource;
use App\Models\AiModel;
use App\Models\Generation;
use App\Models\News;
use App\Models\Photoset;
use App\Services\Admin\Settings;
use App\Services\Generation\GenerationService;
use App\Services\Generation\ImageNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Models, tools, photosets and news: everything the app lists is edited here. */
class CatalogController extends Controller
{
    private const DRIVERS = ['openai', 'kie', 'replicate', 'fake', 'unavailable'];

    private const KINDS = ['text', 'image', 'video', 'song'];

    // ---------- models ----------

    public function models()
    {
        return response()->json([
            'data' => AiModel::whereIn('kind', self::KINDS)->orderBy('kind')->orderBy('sort')->orderBy('id')->get()->map(fn ($m) => $this->model($m)),
            'drivers' => self::DRIVERS,
            'plans' => array_keys(config('neurogpt.plans')),
        ]);
    }

    public function storeModel(Request $request)
    {
        $data = $this->modelData($request, true);
        if (AiModel::where('kind', $data['kind'])->where('slug', $data['slug'])->exists()) {
            throw ValidationException::withMessages(['slug' => 'Модель с таким id уже есть']);
        }
        $data['sort'] ??= (int) AiModel::where('kind', $data['kind'])->max('sort') + 1;
        $model = AiModel::create($data + ['edited_at' => now()]);
        Settings::forgetDeleted("model:{$model->kind}:{$model->slug}");

        return response()->json(['data' => $this->model($model->refresh())], 201);
    }

    public function updateModel(Request $request, AiModel $model)
    {
        abort_unless(in_array($model->kind, self::KINDS, true), 404);
        $model->update($this->modelData($request, false) + ['edited_at' => now()]);

        return response()->json(['data' => $this->model($model)]);
    }

    public function destroyModel(AiModel $model)
    {
        abort_unless(in_array($model->kind, self::KINDS, true), 404);
        Settings::markDeleted("model:{$model->kind}:{$model->slug}");
        $model->delete();

        return response()->json(['ok' => true]);
    }

    private function modelData(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'kind' => [Rule::requiredIf($creating), Rule::in(self::KINDS)],
            'slug' => [Rule::requiredIf($creating), 'string', 'max:32', 'regex:/^[a-z0-9_-]+$/'],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:64'],
            'hint' => ['sometimes', 'nullable', 'string', 'max:255'],
            'price' => [$creating ? 'required' : 'sometimes', 'integer', 'between:0,1000000'],
            'badge' => ['sometimes', 'nullable', 'string', 'max:32'],
            'is_fast' => ['sometimes', 'boolean'],
            'min_plan' => ['sometimes', Rule::in(array_keys(config('neurogpt.plans')))],
            'driver' => ['sometimes', Rule::in(self::DRIVERS)],
            'provider_model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'options' => ['sometimes', 'nullable', 'array'],
            'sort' => ['sometimes', 'integer', 'between:0,65000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (! $creating) {
            unset($data['kind'], $data['slug']);
        }
        if (array_key_exists('options', $data)) {
            $data['options'] = $this->options($data['options']);
        }

        return $data;
    }

    /** Model options: video lengths with price multipliers, formats and the provider's request settings. */
    private function options(?array $raw): ?array
    {
        $out = [];
        foreach ((array) $raw as $key => $value) {
            if (! is_string($key) || ! preg_match('/^[a-z_]{1,32}$/', $key) || $value === null || $value === '' || $value === []) {
                continue;
            }
            if ($key === 'durations') {
                $list = [];
                foreach ((array) $value as $sec => $mult) {
                    if ((int) $sec < 1 || (int) $sec > 600 || ! is_numeric($mult) || $mult <= 0) {
                        throw ValidationException::withMessages(['options' => 'Длительность: секунды от 1 до 600 и множитель больше нуля']);
                    }
                    $list[(int) $sec] = $mult + 0;
                }
                ksort($list);
                $out[$key] = $list;
            } elseif ($key === 'formats') {
                $formats = array_values(array_intersect(['1:1', '9:16', '16:9'], (array) $value));
                if ($formats) {
                    $out[$key] = $formats;
                }
            } elseif (is_scalar($value)) {
                $out[$key] = is_string($value) ? Str::limit($value, 120, '') : $value;
            }
        }

        return $out ?: null;
    }

    private function model(AiModel $m): array
    {
        return [
            'id' => $m->id, 'kind' => $m->kind, 'slug' => $m->slug, 'name' => $m->name, 'hint' => $m->hint, 'price' => $m->price,
            'badge' => $m->badge, 'is_fast' => $m->is_fast, 'min_plan' => $m->min_plan, 'driver' => $m->driver,
            'provider_model' => $m->provider_model, 'options' => $m->options ?: (object) [], 'sort' => $m->sort,
            'is_active' => $m->is_active, 'edited' => $m->edited_at !== null,
        ];
    }

    // ---------- tools: the text lives in settings, the switch and the provider in ai_models ----------

    public function tools()
    {
        $rows = AiModel::where('kind', 'tool')->get()->keyBy('slug');

        return response()->json([
            'data' => collect(config('neurogpt.tools'))->map(fn ($t, $slug) => $this->tool($slug, $t, $rows->get($slug)))->values(),
            'drivers' => self::DRIVERS,
        ]);
    }

    public function saveTool(Request $request, ?string $slug = null)
    {
        $tools = config('neurogpt.tools');
        $creating = $slug === null;
        abort_if(! $creating && ! isset($tools[$slug]), 404);

        $data = $request->validate([
            'slug' => [Rule::requiredIf($creating), 'string', 'max:32', 'regex:/^[a-z0-9_]+$/', Rule::notIn(array_keys($creating ? $tools : []))],
            'name' => ['required', 'string', 'max:64'],
            'hint' => ['nullable', 'string', 'max:255'],
            'cost' => ['required', 'integer', 'between:0,1000000'],
            'icon' => ['nullable', 'string', 'max:24', 'regex:/^[a-z0-9-]+$/'],
            'group' => ['required', Rule::in(['fix', 'create'])],
            'input' => ['required', Rule::in(['none', 'text', 'text_required', 'choice'])],
            'placeholder' => ['nullable', 'string', 'max:120'],
            'prompt' => ['required', 'string', 'max:4000'],
            'input_prefix' => ['nullable', 'string', 'max:200'],
            'output' => ['nullable', Rule::in(['image', 'png', 'video'])],
            'choices' => ['array', 'max:24', Rule::requiredIf($request->input('input') === 'choice')],
            'choices.*.id' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9_]+$/', 'distinct'],
            'choices.*.name' => ['required', 'string', 'max:64'],
            'choices.*.prompt' => ['required', 'string', 'max:1000'],
            'is_active' => ['boolean'],
            'driver' => ['nullable', Rule::in(self::DRIVERS)],
            'provider_model' => ['nullable', 'string', 'max:255'],
            'options' => ['nullable', 'array'],
        ]);
        $slug ??= $data['slug'];

        $tool = array_merge($tools[$slug] ?? ['driver' => 'image'], [
            'name' => $data['name'], 'hint' => (string) ($data['hint'] ?? ''), 'cost' => $data['cost'],
            'icon' => $data['icon'] ?? 'wand', 'group' => $data['group'], 'input' => $data['input'], 'prompt' => $data['prompt'],
        ]);
        foreach (['placeholder', 'input_prefix'] as $key) {
            $tool = $this->setOrDrop($tool, $key, $data[$key] ?? null);
        }
        $tool = $this->setOrDrop($tool, 'output', in_array($data['output'] ?? null, ['png', 'video'], true) ? $data['output'] : null);
        $tool = $this->setOrDrop($tool, 'options', $data['input'] === 'choice'
            ? collect($data['choices'])->mapWithKeys(fn ($c) => [$c['id'] => ['name' => $c['name'], 'prompt' => $c['prompt']]])->all()
            : null);
        $options = $this->options($data['options'] ?? null);
        $tool = $this->setOrDrop($tool, 'duration', $options['duration'] ?? null);

        $row = AiModel::firstOrNew(['kind' => 'tool', 'slug' => $slug]);
        $row->fill([
            'name' => $tool['name'], 'hint' => $tool['hint'], 'price' => $tool['cost'],
            'is_active' => $data['is_active'] ?? ($row->exists ? $row->is_active : true),
            'driver' => $data['driver'] ?? $row->driver ?? config('neurogpt.providers.default'),
            'provider_model' => array_key_exists('provider_model', $data) ? $data['provider_model'] : ($row->provider_model ?? config('neurogpt.providers.openai.image_model')),
            'options' => $options,
            'sort' => $row->exists ? $row->sort : (int) AiModel::where('kind', 'tool')->max('sort') + 1,
            'edited_at' => now(),
        ])->save();

        $tools[$slug] = $tool;
        Settings::put('tools', $tools);
        Settings::forgetDeleted("model:tool:$slug");

        return response()->json(['data' => $this->tool($slug, $tool, $row)], $creating ? 201 : 200);
    }

    public function destroyTool(string $slug)
    {
        $tools = config('neurogpt.tools');
        abort_unless(isset($tools[$slug]), 404);
        unset($tools[$slug]);
        Settings::put('tools', $tools);
        Settings::markDeleted("model:tool:$slug");
        AiModel::where('kind', 'tool')->where('slug', $slug)->delete();

        return response()->json(['ok' => true]);
    }

    private function setOrDrop(array $tool, string $key, mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            unset($tool[$key]);
        } else {
            $tool[$key] = $value;
        }

        return $tool;
    }

    private function tool(string $slug, array $t, ?AiModel $row): array
    {
        return [
            'slug' => $slug, 'name' => $t['name'], 'hint' => $t['hint'] ?? '', 'cost' => (int) $t['cost'], 'icon' => $t['icon'] ?? 'wand',
            'group' => $t['group'] ?? 'fix', 'input' => $t['input'] ?? 'none', 'placeholder' => $t['placeholder'] ?? '',
            'prompt' => $t['prompt'] ?? '', 'input_prefix' => $t['input_prefix'] ?? '', 'output' => $t['output'] ?? 'image',
            'choices' => collect($t['options'] ?? [])->map(fn ($o, $id) => ['id' => (string) $id, 'name' => $o['name'], 'prompt' => $o['prompt']])->values(),
            'is_active' => (bool) $row?->is_active, 'driver' => $row?->driver, 'provider_model' => $row?->provider_model,
            'options' => $row?->options ?: (object) [],
        ];
    }

    // ---------- photosets ----------

    public function photosets()
    {
        return response()->json(['data' => Photoset::orderBy('sort')->orderBy('id')->get()->map(fn ($p) => $this->photoset($p))]);
    }

    public function savePhotoset(Request $request, ?Photoset $photoset = null)
    {
        $creating = $photoset === null;
        $data = $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'prompt' => [$creating ? 'required' : 'sometimes', 'string', 'max:6000'],
            'cover_url' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:#^(https://|/storage/)#'],
            'seed' => ['sometimes', 'integer', 'between:0,100000'],
            'palette' => ['sometimes', 'integer', 'between:0,4'],
            'is_trending' => ['sometimes', 'boolean'],
            'trend_hint' => ['sometimes', 'nullable', 'string', 'max:64'],
            'sort' => ['sometimes', 'integer', 'between:0,65000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $photoset ??= new Photoset(['sort' => (int) Photoset::max('sort') + 1]);
        $photoset->fill($data + ['edited_at' => now()])->save();
        Settings::forgetDeleted("photoset:{$photoset->name}");

        return response()->json(['data' => $this->photoset($photoset->refresh())], $creating ? 201 : 200);
    }

    public function destroyPhotoset(Photoset $photoset)
    {
        Settings::markDeleted("photoset:{$photoset->name}");
        $photoset->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Starts a free generation with this photoset's prompt, so the admin gets a real example for the cover.
     * A photo is optional: without one the model invents a person. The app polls /api/generations/{id} as usual.
     */
    public function photosetExample(Request $request, Photoset $photoset, GenerationService $service)
    {
        $request->validate([
            'file' => ['nullable', 'file', 'max:'.config('neurogpt.upload_max_kb'), 'mimetypes:image/jpeg,image/png,image/webp'],
            'model' => ['nullable', 'string', 'max:32', 'regex:/^[a-z0-9_-]+$/'],
        ]);
        abort_unless($photoset->is_active, 422, 'Сначала включите фотосет');

        try {
            [$generation] = $service->create($request->user(), [
                'kind' => 'image', 'photoset_id' => $photoset->id, 'format' => '9:16', 'model' => $request->input('model'),
                'prompt' => $request->hasFile('file') ? '' : 'No reference photo is attached for this example: show a fictional adult person who fits the scene.',
            ], $request->file('file'), free: true);
        } catch (TooManyActive $e) {
            throw ValidationException::withMessages(['file' => 'Дождитесь окончания предыдущих генераций']);
        }

        return response()->json(['data' => new GenerationResource($generation)], 201);
    }

    /** Makes the admin's own finished example the cover of the photoset. */
    public function photosetCover(Request $request, Photoset $photoset)
    {
        $data = $request->validate(['generation_id' => ['required', 'integer']]);
        $g = Generation::where('user_id', $request->user()->id)->where('photoset_id', $photoset->id)->where('status', 'done')
            ->whereNotNull('result_path')->find($data['generation_id']);
        $disk = Storage::disk('public');
        if (! $g || ! $disk->exists($g->result_path)) {
            throw ValidationException::withMessages(['generation_id' => 'Пример не найден или ещё не готов']);
        }
        // A copy: the cover stays even if the generation is deleted later.
        $path = 'covers/'.Str::uuid().'.'.strtolower(pathinfo($g->result_path, PATHINFO_EXTENSION));
        $disk->copy($g->result_path, $path);
        $photoset->update(['cover_url' => '/storage/'.$path, 'edited_at' => now()]);

        return response()->json(['data' => $this->photoset($photoset)]);
    }

    private function photoset(Photoset $p): array
    {
        return $p->only(['id', 'name', 'description', 'prompt', 'cover_url', 'seed', 'palette', 'is_trending', 'trend_hint', 'sort', 'is_active']);
    }

    // ---------- news cards ----------

    public function news()
    {
        return response()->json(['data' => News::orderBy('sort')->orderBy('id')->get()->map(fn ($n) => $this->newsItem($n))]);
    }

    public function saveNews(Request $request, ?News $news = null)
    {
        $creating = $news === null;
        $data = $request->validate([
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:160'],
            'sticker' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^[a-z0-9-]+$/'],
            'style' => ['sometimes', Rule::in(['ember', 'ink', 'gold'])],
            'action' => ['sometimes', 'nullable', Rule::in(['topup', 'models', 'invite', 'plans', 'video'])],
            'sort' => ['sometimes', 'integer', 'between:0,65000'],
            'is_active' => ['sometimes', 'boolean'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
        ]);
        $news ??= new News(['sort' => (int) News::max('sort') + 1]);
        $news->fill($data + ['edited_at' => now()])->save();
        Settings::forgetDeleted("news:{$news->title}");

        return response()->json(['data' => $this->newsItem($news->refresh())], $creating ? 201 : 200);
    }

    public function destroyNews(News $news)
    {
        Settings::markDeleted("news:{$news->title}");
        $news->delete();

        return response()->json(['ok' => true]);
    }

    private function newsItem(News $n): array
    {
        return $n->only(['id', 'title', 'subtitle', 'sticker', 'style', 'action', 'sort', 'is_active']) + ['ends_at' => $n->ends_at?->toIso8601String()];
    }

    // ---------- pictures for covers ----------

    public function upload(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'max:'.config('neurogpt.upload_max_kb'), 'mimetypes:image/jpeg,image/png,image/webp']]);
        $image = ImageNormalizer::upload($request->file('file')->getRealPath());
        if (! @getimagesizefromstring($image->bytes)) {
            throw ValidationException::withMessages(['file' => 'Не получилось прочитать картинку']);
        }
        $path = 'covers/'.Str::uuid().'.'.$image->extension;
        Storage::disk('public')->put($path, $image->bytes);

        return response()->json(['url' => '/storage/'.$path], 201);
    }
}
