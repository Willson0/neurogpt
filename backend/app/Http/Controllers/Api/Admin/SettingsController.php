<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Services\Admin\Settings;
use App\Services\Payments\PaymentService;
use App\Services\Payments\SubscriptionCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Prices, plans, bonuses, limits, styles and the color theme. */
class SettingsController extends Controller
{
    /** CSS variables of the app the admin may recolor. */
    public const THEME_VARS = [
        '--color-accent', '--color-accent-ink', '--color-focus', '--plasma-1', '--plasma-2', '--plasma-3', '--on-plasma',
        '--color-page', '--color-paper', '--color-surface', '--color-surface-2', '--color-rule',
        '--color-ink', '--color-muted', '--color-cta', '--color-cta-ink', '--gold', '--color-success', '--color-danger',
    ];

    public function show()
    {
        return response()->json(['data' => $this->values(), 'theme_vars' => self::THEME_VARS]);
    }

    /** Body: {"values": {"bonus": {...}, "packs": [...]}}. Only the keys sent are changed. */
    public function update(Request $request)
    {
        $values = $request->input('values');
        if (! is_array($values) || ! $values) {
            throw ValidationException::withMessages(['values' => 'Нечего сохранять']);
        }

        $clean = [];
        foreach ($values as $key => $value) {
            $rules = $this->rules()[$key] ?? null;
            if (! $rules) {
                throw ValidationException::withMessages([$key => 'Неизвестная настройка']);
            }
            $validator = Validator::make(['v' => $value], $rules);
            if ($validator->fails()) {
                throw ValidationException::withMessages([$key => $validator->errors()->first()]);
            }
            $clean[$key] = $this->normalize($key, $value);
        }

        DB::transaction(function () use ($clean) {
            foreach ($clean as $key => $value) {
                Settings::put($key, $value);
            }
            if (isset($clean['features'])) {
                // The "Песня" tab needs its models switched on together with the feature.
                AiModel::where('kind', 'song')->update(['is_active' => (bool) config('neurogpt.features.songs')]);
            }
        });

        return response()->json(['data' => $this->values()]);
    }

    private function values(): array
    {
        $all = Settings::all();
        unset($all['deleted'], $all['admins'], $all['yookassa'], $all['renewal']);
        $all['theme'] = ['dark' => (object) ($all['theme']['dark'] ?? []), 'light' => (object) ($all['theme']['light'] ?? [])];
        $all['subscription_offers'] = SubscriptionCatalog::offers();
        $all['packs'] = PaymentService::tokenOffers();

        return $all;
    }

    private function rules(): array
    {
        $color = ['string', 'regex:/^#[0-9a-fA-F]{6}$/'];
        $theme = [];
        foreach (['dark', 'light'] as $scheme) {
            $theme["v.$scheme"] = ['nullable', 'array:'.implode(',', self::THEME_VARS)];
            $theme["v.$scheme.*"] = $color;
        }

        return [
            'bonus' => [
                'v' => ['required', 'array:signup,daily,referral'],
                'v.*' => ['integer', 'between:0,1000000'],
            ],
            'limits' => [
                'v' => ['required', 'array:active_generations,referral_rewards,improve_per_day,generation_timeout'],
                'v.active_generations' => ['integer', 'between:1,100'],
                'v.referral_rewards' => ['integer', 'between:0,1000000'],
                'v.improve_per_day' => ['integer', 'between:0,100000'],
                'v.generation_timeout' => ['integer', 'between:60,86400'],
            ],
            'features' => ['v' => ['required', 'array:songs'], 'v.songs' => ['boolean']],
            'stars' => [
                'v' => ['required', 'array:enabled,rub_per_star'],
                'v.enabled' => ['boolean'],
                'v.rub_per_star' => ['numeric', 'between:0.1,1000'],
            ],
            'styles' => [
                'v' => ['required', 'array', 'max:40'],
                'v.any' => ['required', 'array'],
                'v.*.name' => ['required', 'string', 'max:32'],
                'v.*.prompt' => ['nullable', 'string', 'max:1000'],
            ],
            'theme' => ['v' => ['required', 'array:dark,light']] + $theme,
            'upload_max_kb' => ['v' => ['required', 'integer', 'between:512,10240']],
            'telegram.support_url' => ['v' => ['nullable', 'string', 'max:255', 'regex:#^https://#']],
            'providers.openai.text_model' => ['v' => ['required', 'string', 'max:120']],
            'providers.openai.image_model' => ['v' => ['required', 'string', 'max:120']],
            'providers.kie.translate' => ['v' => ['required', 'boolean']],
        ];
    }

    private function normalize(string $key, mixed $value): mixed
    {
        return match ($key) {
            'bonus', 'limits' => array_map('intval', $value),
            'features' => ['songs' => (bool) ($value['songs'] ?? false)],
            'stars' => array_filter([
                'enabled' => isset($value['enabled']) ? (bool) $value['enabled'] : null,
                'rub_per_star' => isset($value['rub_per_star']) ? (float) $value['rub_per_star'] : null,
            ], fn ($v) => $v !== null),
            'styles' => $this->styles($value),
            'theme' => ['dark' => (array) ($value['dark'] ?? []), 'light' => (array) ($value['light'] ?? [])],
            'upload_max_kb' => (int) $value,
            'providers.kie.translate' => (bool) $value,
            'telegram.support_url' => $value ?: null,
            default => $value,
        };
    }

    private function styles(array $value): array
    {
        $out = [];
        foreach ($value as $id => $style) {
            if (! preg_match('/^[a-z0-9_-]{1,32}$/', (string) $id)) {
                throw ValidationException::withMessages(['styles' => 'Id стиля: латиница, цифры, дефис']);
            }
            $out[(string) $id] = ['name' => $style['name'], 'prompt' => ($style['prompt'] ?? '') !== '' ? $style['prompt'] : null];
        }

        return $out;
    }
}
