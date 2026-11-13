<?php

namespace App\Services\Generation;

use App\Models\AiModel;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Small text-model helpers around prompts: "improve my idea" and translation for video models. */
class PromptService
{
    private const IMPROVE = [
        'image' => <<<'TXT'
        You are an expert prompt writer for an AI image generator. Rewrite the user's idea into one vivid, concrete prompt.
        Rules:
        - Keep the user's subject, intent and every detail they gave. Never replace the idea with another one.
        - Add what is missing: setting, composition and framing, lighting, color palette, mood, materials and textures. Add an art style only if the user did not name one.
        - One paragraph, 30 to 70 words, no lists, no quotes, no explanations, no technical parameters like "--ar" or "8k".
        - Write in the same language as the user's text.
        - If the text is unsafe or meaningless, return it unchanged.
        Output only the prompt.
        TXT,
        'video' => <<<'TXT'
        You are an expert prompt writer for an AI video generator that makes one continuous 5-10 second shot. Rewrite the user's idea into one concrete prompt.
        Rules:
        - Keep the user's subject, intent and every detail they gave. Never replace the idea with another one.
        - Describe, in this order: the subject and what it does (one clear action), the setting, the camera (shot size and one movement such as slow push-in, tracking, orbit), lighting and mood, and the sound if it matters.
        - A single shot: no cuts, no scene changes, no on-screen text.
        - One paragraph, 30 to 70 words, no lists, no quotes, no explanations.
        - Write in the same language as the user's text.
        - If the text is unsafe or meaningless, return it unchanged.
        Output only the prompt.
        TXT,
    ];

    private const TRANSLATE = <<<'TXT'
    Translate the user's text into natural English for an AI video generator. Keep every detail and the meaning, do not add or remove anything, keep names as they are. If the text is already English, return it unchanged. Output only the translation, without quotes or comments.
    TXT;

    public function available(): bool
    {
        return (bool) config('neurogpt.providers.openai.api_key');
    }

    /** A richer version of the user's prompt. Falls back to a simple rule-based one when no text model is connected. */
    public function improve(string $prompt, string $kind = 'image'): string
    {
        $prompt = trim($prompt);
        $kind = $kind === 'video' ? 'video' : 'image';

        if ($this->available()) {
            try {
                $better = $this->ask(self::IMPROVE[$kind], $prompt);
                if ($better !== '' && mb_strlen($better) <= 1500) {
                    return $better;
                }
            } catch (Throwable $e) {
                Log::warning('Prompt improve failed', ['error' => $e->getMessage()]);
            }
        }

        $tail = $kind === 'video'
            ? ', плавное движение камеры, кинематографичный свет, реалистичные детали'
            : ', продуманная композиция, мягкий объёмный свет, высокая детализация';

        return str_contains($prompt, trim($tail, ', ')) ? $prompt : rtrim($prompt, " .,;\t\n").$tail;
    }

    /** English version of a prompt for models that follow English best. The original is returned on any failure. */
    public function toEnglish(string $prompt): string
    {
        $prompt = trim($prompt);
        if ($prompt === '' || ! preg_match('/[^\x00-\x7F]/', $prompt) || ! $this->available() || ! config('neurogpt.providers.kie.translate')) {
            return $prompt;
        }

        try {
            $english = $this->ask(self::TRANSLATE, $prompt);

            return $english !== '' ? $english : $prompt;
        } catch (Throwable $e) {
            Log::warning('Prompt translation failed', ['error' => $e->getMessage()]);

            return $prompt;
        }
    }

    private function ask(string $system, string $text): string
    {
        $model = new AiModel(['kind' => 'text', 'provider_model' => config('neurogpt.providers.openai.text_model')]);
        $answer = app(OpenAiProvider::class)->chat($model, [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $text],
        ], 30);

        return trim(trim($answer), "\"'«»“” \n");
    }
}
