<?php

namespace App\Services\Generation;

use App\Models\AiModel;
use App\Models\Generation;

/** Offline stand-in for local development and tests. */
class FakeProvider implements Provider
{
    public function chat(AiModel $model, array $messages, ?int $timeout = null): string
    {
        $last = collect($messages)->last()['content'] ?? '';

        return 'Тестовый ответ модели «'.$model->name.'». Ваш запрос: '.mb_substr($last, 0, 200);
    }

    public function media(Generation $generation, AiModel $model, ?string $inputPath): MediaResult
    {
        if ($generation->kind === 'song') {
            $music = $generation->params['music'] ?? null;
            if ($music) {
                $data = match ($music['operation']) {
                    'lyrics' => ['lyrics' => [['title' => 'Пробный текст', 'text' => "[Verse]\n".$music['input']['prompt']."\n\n[Chorus]\nМузыка звучит"]]],
                    'boost_style' => ['style' => mb_substr($music['input']['content'].', warm textures, steady rhythm and a clear melody', 0, 1000)],
                    'voice_phrase', 'voice_retry' => ['phrase' => 'This is my own singing voice. '.$generation->id, 'voice_task' => 'dev-'.$generation->id],
                    'persona', 'voice_create' => ['profile_id' => 'dev-'.$generation->id, 'profile_type' => $music['operation'] === 'persona' ? 'style_persona' : 'voice_persona', 'name' => $music['name']],
                    default => [],
                };
                if ($data) {
                    return new MediaResult('', '', $data);
                }
                if ($music['operation'] !== 'cover') {
                    return $this->tone($music['input']['duration'] ?? 6);
                }
            } else {
                return $this->tone((int) ($generation->params['duration'] ?? 30));
            }
        }

        mt_srand($generation->id * 7919);
        $c = fn () => sprintf('#%02x%02x%02x', mt_rand(40, 230), mt_rand(40, 230), mt_rand(40, 230));
        [$w, $h] = match ($generation->params['format'] ?? '1:1') {
            '9:16' => [576, 1024],
            '16:9' => [1024, 576],
            default => [768, 768],
        };
        $svg = <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" width="$w" height="$h" viewBox="0 0 $w $h">
          <defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="{$c()}"/><stop offset="1" stop-color="{$c()}"/></linearGradient></defs>
          <rect width="100%" height="100%" fill="url(#g)"/>
          <circle cx="{$this->r($w)}" cy="{$this->r($h)}" r="{$this->r(min($w, $h) / 3)}" fill="{$c()}" opacity=".55"/>
          <circle cx="{$this->r($w)}" cy="{$this->r($h)}" r="{$this->r(min($w, $h) / 4)}" fill="{$c()}" opacity=".45"/>
        </svg>
        SVG;

        if (($generation->params['music']['operation'] ?? null) === 'cover') {
            $preview = imagecreatetruecolor(400, 400);
            imagefill($preview, 0, 0, imagecolorallocate($preview, 80, 27, 55));
            imagefilledellipse($preview, 200, 200, 260, 260, imagecolorallocate($preview, 247, 113, 82));
            ob_start();
            imagepng($preview);

            return new MediaResult((string) ob_get_clean(), 'png', ['cover' => true]);
        }

        return new MediaResult($svg, 'svg');
    }

    /** A short quiet chord as WAV, enough to try the player locally. */
    private function tone(int $seconds): MediaResult
    {
        $rate = 8000;
        $n = $rate * min($seconds, 6);
        $pcm = '';
        for ($i = 0; $i < $n; $i++) {
            $t = $i / $rate;
            $v = (sin(2 * M_PI * 440 * $t) + sin(2 * M_PI * 554 * $t) + sin(2 * M_PI * 659 * $t)) / 3 * 0.25 * min(1, ($n - $i) / $rate);
            $pcm .= pack('v', (int) ($v * 32767) & 0xFFFF);
        }
        $head = 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16).'data'.pack('V', strlen($pcm));

        return new MediaResult($head.$pcm, 'wav');
    }

    private function r(float|int $max): int
    {
        return mt_rand((int) ($max * 0.15), (int) max($max * 0.85, 1));
    }
}
