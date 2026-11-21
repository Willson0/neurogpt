<?php

namespace App\Services\Generation;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MusicStudio
{
    public const MODELS = ['suno-v6' => 'V6', 'suno-v6-mini' => 'V6_MINI', 'suno-v6-wild' => 'V6_WILD'];

    public const REFERENCES = [
        'image_urls' => [5, 'image/jpeg,image/png,image/webp,image/bmp,image/x-ms-bmp'],
        'video_urls' => [1, 'video/mp4,video/quicktime,video/webm'],
        'audio_urls' => [10, 'audio/mpeg,audio/mp4,audio/aac,audio/x-wav,audio/wav,audio/ogg,audio/flac,audio/x-flac,audio/webm,video/webm'],
    ];

    public const OPERATIONS = [
        'generate' => ['generate', 'Создание песни'],
        'lyrics' => ['generate-lyrics', 'Генерация текста'],
        'extend' => ['extend', 'Продолжение песни'],
        'replace' => ['replace-section', 'Замена фрагмента'],
        'cover_audio' => ['upload-and-cover-audio', 'Кавер-версия'],
        'extend_upload' => ['upload-and-extend-audio', 'Продолжение аудио'],
        'add_vocals' => ['add-vocals', 'Добавление вокала'],
        'add_instrumental' => ['add-instrumental', 'Добавление аккомпанемента'],
        'mashup' => ['mashup', 'Сведение двух треков'],
        'wav' => ['convert-to-wav-format', 'Экспорт WAV'],
        'stems' => ['separate-vocals', 'Разделение дорожек'],
        'boost_style' => ['boost-music-style', 'Уточнение стиля'],
        'persona' => ['generate-persona', 'Музыкальный стиль'],
        'voice_phrase' => ['validation-phrase', 'Фраза для проверки голоса'],
        'voice_retry' => ['regenerate-phrase', 'Новая фраза для проверки'],
        'voice_create' => ['create-voice', 'Создание голоса'],
        'cover' => ['seedream/4.5-text-to-image', 'Обложка песни'],
    ];

    public function prepare(Request $request): array
    {
        $operation = $request->validate(['operation' => ['required', Rule::in(array_keys(self::OPERATIONS))]])['operation'];
        $rules = [
            'model' => ['nullable', Rule::in(array_keys(self::MODELS))],
            'source_id' => ['nullable', 'integer'],
            'track' => ['nullable', 'integer', 'min:0'],
            'profile_id' => ['nullable', 'integer'],
            'prompt' => ['nullable', 'string', 'max:3000'],
            'title' => ['nullable', 'string', 'max:80'],
            'style' => ['nullable', 'string', 'max:1000'],
            'lyrics' => ['nullable', 'string', 'max:5000'],
            'negative_tags' => ['nullable', 'string', 'max:1000'],
            'custom_mode' => ['boolean'],
            'instrumental' => ['boolean'],
            'duration' => ['nullable', 'integer', 'between:10,360'],
            'vocal_gender' => ['nullable', Rule::in(['m', 'f'])],
            'style_weight' => ['nullable', 'numeric', 'between:0,1', 'decimal:0,2'],
            'weirdness_constraint' => ['nullable', 'numeric', 'between:0,1', 'decimal:0,2'],
            'audio_weight' => ['nullable', 'numeric', 'between:0,1', 'decimal:0,2'],
            'variety' => ['nullable', 'integer', 'between:0,4'],
            'description' => ['nullable', 'string', 'max:1000'],
            'start' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'end' => ['nullable', 'numeric', 'gt:start', 'decimal:0,2'],
            'language' => ['nullable', 'string', 'regex:/^[a-z]{2}$/'],
            'singer_skill_level' => ['nullable', Rule::in(['beginner', 'intermediate', 'professional'])],
            'stem_type' => ['nullable', Rule::in(['separate_vocal', 'split_stem', 'split_stem_advanced'])],
            'stem_name' => ['nullable', 'string', 'max:60'],
            'references' => ['nullable', 'array:image_urls,video_urls,audio_urls'],
            'mashup_files' => ['required_if:operation,mashup', 'array', 'size:2'],
            'mashup_files.*' => ['required', 'file', 'max:20480', 'mimetypes:'.self::REFERENCES['audio_urls'][1]],
            'auto_send' => ['boolean'],
            'file' => ['nullable', 'file', 'max:20480', 'mimetypes:audio/mpeg,audio/mp4,audio/aac,audio/x-wav,audio/wav,audio/ogg,audio/flac,audio/x-flac,audio/webm,video/webm'],
        ];
        foreach (self::REFERENCES as $key => [$limit, $mime]) {
            $rules['references.'.$key] = ['nullable', 'array', 'max:'.$limit];
            $max = $key === 'image_urls' ? 10240 : 20480;
            $rules['references.'.$key.'.*'] = ['required', 'file', 'max:'.$max, 'mimetypes:'.$mime];
        }

        $data = array_filter($request->validate($rules), fn ($value) => $value !== null && $value !== '');
        $user = $request->user();
        $file = $request->file('file');
        $model = $data['model'] ?? 'suno-v6';
        $input = [];
        $parentId = null;
        $previousPhrase = null;
        $fileField = null;
        $uploads = [];

        $advanced = Arr::only($data, ['negative_tags', 'vocal_gender', 'style_weight', 'weirdness_constraint', 'audio_weight', 'variety']);
        foreach (['style_weight', 'weirdness_constraint', 'audio_weight'] as $key) {
            if (isset($advanced[$key])) {
                $advanced[$key] = (float) $advanced[$key];
            }
        }
        if (isset($advanced['variety'])) {
            $advanced['variety'] = (int) $advanced['variety'];
        }
        $instrumental = $request->boolean('instrumental');
        if ($instrumental) {
            unset($advanced['vocal_gender'], $advanced['audio_weight'], $data['lyrics']);
        }
        $description = Arr::only($data, ['title', 'style', 'lyrics']);
        if (isset($data['profile_id']) && in_array($operation, ['generate', 'extend', 'replace', 'cover_audio', 'extend_upload', 'mashup'], true)) {
            $profile = $this->owned($user, $data['profile_id']);
            $profileId = $profile->music_result['profile_id'] ?? null;
            $profileType = $profile->music_result['profile_type'] ?? null;
            $this->require($profileId && (! $instrumental || $profileType === 'style_persona'), 'profile_id', 'Выберите доступный шаблон или голос');
            $advanced += ['persona_id' => $profileId, 'persona_model' => $profileType];
        }

        if (in_array($operation, ['extend', 'replace', 'persona', 'cover', 'wav', 'stems'], true)) {
            [$source, $track] = $this->track($user, $data['source_id'] ?? null, (int) ($data['track'] ?? 0));
            $parentId = $source->id;
            $sourceModel = array_search($source->params['music']['version'] ?? null, self::MODELS, true);
            if ($sourceModel && $operation === 'extend') {
                $model = $sourceModel;
            }
            if ($operation !== 'cover') {
                $this->require(! empty($track['id']) && $source->provider_task_id, 'source_id', 'Эту дорожку нельзя редактировать в Suno');
            }
        }

        switch ($operation) {
            case 'generate':
                $custom = $request->boolean('custom_mode');
                $references = $data['references'] ?? [];
                $input = ['custom_mode' => $custom, 'instrumental' => $instrumental] + $description;
                if ($custom) {
                    $this->require(isset($data['title']), 'title', 'Введите название песни');
                    $this->require(isset($data['style']) || isset($data['lyrics']) || isset($advanced['negative_tags']), 'style', 'Добавьте стиль, текст или исключения');
                    $input += $advanced;
                    if (isset($data['duration'])) {
                        $input['duration'] = (int) $data['duration'];
                    }
                } else {
                    $attachments = array_sum(array_map('count', $references)) + (int) isset($data['style']) + (int) isset($data['lyrics']);
                    $this->require($attachments <= 10, 'audio_urls', 'Можно добавить до 10 референсов вместе со стилем и текстом');
                    $this->require(isset($data['prompt']) || $attachments > 0, 'prompt', 'Опишите идею или добавьте референс');
                    // The published example allows prompt alone. Include it as a style reference too when it is the only input.
                    if ($attachments === 0) {
                        $input['style'] = mb_substr($data['prompt'], 0, 1000);
                    }
                    $uploads = $references;
                    $input += Arr::only($data, ['prompt']) + Arr::only($advanced, ['negative_tags', 'persona_id', 'persona_model']);
                }
                break;

            case 'lyrics':
                $input['prompt'] = $this->field($data, 'prompt', 'Опишите тему песни');
                $this->require(mb_strlen($input['prompt']) <= 200, 'prompt', 'Описание текста должно быть не длиннее 200 символов');
                break;

            case 'boost_style':
                $input['content'] = $this->field($data, 'style', 'Опишите музыкальный стиль');
                break;

            case 'wav':
            case 'stems':
                $input = ['task_id' => $source->provider_task_id, 'audio_id' => $track['id']];
                $data['title'] = mb_substr($track['title'] ?: 'Аудио', 0, 72).($operation === 'wav' ? ' · WAV' : '');
                if ($operation === 'stems') {
                    $input['type'] = $data['stem_type'] ?? 'separate_vocal';
                    if ($input['type'] === 'split_stem_advanced') {
                        $input['stem_name'] = $this->field($data, 'stem_name', 'Укажите инструмент или голос для выделения');
                    }
                }
                break;

            case 'extend':
            case 'extend_upload':
                $input = ['instrumental' => $instrumental] + $description + $advanced;
                if ($operation === 'extend') {
                    $input['audio_id'] = $track['id'];
                } else {
                    $fileField = 'upload_url';
                }
                if (isset($data['start'])) {
                    $start = (float) $data['start'];
                    $this->require($start > 0 && (empty($track['duration']) || $start < $track['duration']), 'start', 'Укажите точку продолжения внутри дорожки');
                    $input['continue_at'] = $start;
                }
                break;

            case 'replace':
                $start = (float) $this->field($data, 'start', 'Укажите начало фрагмента');
                $end = (float) $this->field($data, 'end', 'Укажите конец фрагмента');
                $duration = (float) ($track['duration'] ?? 0);
                $this->require(round($end - $start, 2) >= 10 && (! $duration || ($end <= $duration && $end - $start <= $duration / 2)),
                    'end', 'Выберите фрагмент от 10 секунд до половины длины дорожки');
                $input = [
                    'task_id' => $source->provider_task_id, 'audio_id' => $track['id'],
                    'prompt' => $this->field($data, 'prompt', 'Опишите, что изменить'),
                    'tags' => $this->field($data, 'style', 'Укажите стиль фрагмента'),
                    'title' => $data['title'] ?? mb_substr($track['title'] ?: 'Новая версия', 0, 80),
                    'infill_start_s' => $start, 'infill_end_s' => $end,
                    'full_lyrics' => $data['lyrics'] ?? $track['lyrics'] ?? '',
                ] + $advanced;
                if (isset($data['description'])) {
                    $input['lyrics'] = $data['description'];
                }
                break;

            case 'cover_audio':
            case 'add_vocals':
            case 'add_instrumental':
                $fileField = 'upload_url';
                $input = $description + $advanced;
                if ($operation === 'cover_audio') {
                    $input['instrumental'] = $instrumental;
                } else {
                    $input['title'] = $this->field($data, 'title', 'Укажите название песни');
                    $input['negative_tags'] = $this->field($data, 'negative_tags', 'Укажите, чего избегать');
                    $style = $this->field($data, 'style', 'Укажите музыкальный стиль');
                    if ($operation === 'add_instrumental') {
                        unset($input['style']);
                        $input['tags'] = $style;
                    }
                }
                break;

            case 'mashup':
                $uploads = ['upload_url_list' => $data['mashup_files']];
                $input = [
                    'title' => $this->field($data, 'title', 'Укажите название песни'),
                    'style' => $this->field($data, 'style', 'Укажите музыкальный стиль'),
                ] + $description + Arr::except($advanced, ['negative_tags']);
                if (isset($data['duration'])) {
                    $input['duration'] = (int) $data['duration'];
                }
                break;

            case 'persona':
                $this->require(in_array($source->params['music']['operation'] ?? '', ['generate', 'extend'], true), 'source_id', 'Шаблон можно создать из сгенерированной или продолженной песни');
                $input = [
                    'task_id' => $source->provider_task_id, 'audio_id' => $track['id'],
                    'name' => $this->field($data, 'title', 'Назовите шаблон'),
                    'description' => $this->field($data, 'description', 'Опишите характер шаблона'),
                ] + Arr::only($data, ['style']);
                if (isset($data['start'], $data['end'])) {
                    $this->require(empty($track['duration']) || $data['end'] <= $track['duration'], 'end', 'Фрагмент должен находиться внутри дорожки');
                    $input += ['vocal_start' => (float) $data['start'], 'vocal_end' => (float) $data['end']];
                }
                break;

            case 'voice_phrase':
                $fileField = 'voice_url';
                $input = [
                    'vocal_start_s' => (float) ($data['start'] ?? 0),
                    'vocal_end_s' => (float) $this->field($data, 'end', 'Укажите конец фрагмента с голосом'),
                    'language' => $data['language'] ?? 'en',
                ];
                break;

            case 'voice_retry':
            case 'voice_create':
                $source = $this->owned($user, $data['source_id'] ?? null);
                $this->require(! empty($source->music_result['phrase']), 'source_id', 'Сначала получите проверочную фразу');
                $parentId = $source->id;
                $input['task_id'] = $source->music_result['voice_task'] ?? $source->provider_task_id;
                $previousPhrase = $source->music_result['phrase'];
                if ($operation === 'voice_create') {
                    $fileField = 'verify_url';
                    $input['voice_name'] = $this->field($data, 'title', 'Введите имя голоса');
                    $input += Arr::only($data, ['description', 'style', 'singer_skill_level']);
                }
                break;

            case 'cover':
                $brief = $this->field($data, 'prompt', 'Опишите обложку');
                $input = ['prompt' => 'Square music release cover artwork. '.$brief, 'aspect_ratio' => '1:1',
                    'quality' => 'basic', 'nsfw_checker' => true];
                break;
        }

        if ($fileField) {
            $this->require($file !== null, 'file', 'Выберите аудиофайл до 20 МБ');
        }
        $total = array_sum(array_map(fn ($files) => array_sum(array_map(fn ($file) => $file->getSize(), $files)), $uploads));
        $this->require($total <= 50 * 1024 * 1024, 'references', 'Общий размер файлов — до 50 МБ');
        if (in_array($operation, ['generate', 'extend', 'extend_upload', 'cover_audio', 'add_vocals', 'add_instrumental', 'mashup'], true)) {
            $input['model'] = self::MODELS[$model];
        }

        return [
            'kind' => 'song',
            'model' => $model,
            'prompt' => $data['prompt'] ?? $data['title'] ?? '',
            'music_uploads' => $uploads,
            'music' => [
                'operation' => $operation,
                'label' => self::OPERATIONS[$operation][1],
                'input' => $input,
                'version' => self::MODELS[$model],
                'name' => $data['title'] ?? null,
                'parent_id' => $parentId,
                'track' => (int) ($data['track'] ?? 0),
                'file_field' => $fileField,
                'upload_fields' => array_keys($uploads),
                'profile_id' => $data['profile_id'] ?? null,
                'previous_phrase' => $previousPhrase,
                'auto_send' => $request->boolean('auto_send'),
            ],
        ];
    }

    public function owned(User $user, ?int $id): Generation
    {
        return Generation::where('user_id', $user->id)->where('kind', 'song')->where('status', 'done')->findOrFail($id);
    }

    public function track(User $user, ?int $id, int $index): array
    {
        $generation = $this->owned($user, $id);
        $track = $generation->music_result['tracks'][$index] ?? null;
        if (! $track && $index === 0 && $generation->result_path && ! isset($generation->params['music'])) {
            $track = ['path' => $generation->result_path, 'title' => $generation->title()];
        }
        abort_unless($track && ! empty($track['path']), 404);

        return [$generation, $track];
    }

    private function field(array $data, string $key, string $message): mixed
    {
        $this->require(isset($data[$key]), $key, $message);

        return $data[$key];
    }

    private function require(bool $condition, string $key, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$key => $message]);
        }
    }
}
