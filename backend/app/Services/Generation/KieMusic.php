<?php

namespace App\Services\Generation;

use App\Models\Generation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class KieMusic
{
    public function request(Generation $generation, ?string $uploadUrl): array
    {
        $music = $generation->params['music'];
        $operation = $music['operation'];
        $input = $music['input'];
        if ($music['file_field']) {
            $input[$music['file_field']] = $uploadUrl ?? throw new RuntimeException('Missing source audio');
        }

        return [
            'model' => ($operation === 'cover' ? '' : 'ai-music-api/').MusicStudio::OPERATIONS[$operation][0],
            'input' => $input,
        ];
    }

    public function recordId(Generation $generation): string
    {
        $music = $generation->params['music'];

        return in_array($music['operation'], ['voice_create', 'voice_retry'], true)
            ? $music['input']['task_id']
            : $generation->provider_task_id;
    }

    public function finished(Generation $generation, array $data, array $result): ?MediaResult
    {
        $music = $generation->params['music'];
        $operation = $music['operation'];
        $state = $data['state'] ?? '';

        // Suno leaves the verification task generating until the user reads the phrase.
        if (in_array($operation, ['voice_phrase', 'voice_retry'], true)) {
            $phrase = $result['data']['verify_phrase_text'] ?? null;
            if (is_string($phrase) && $phrase !== '' && ($operation !== 'voice_retry' || $phrase !== $music['previous_phrase'])) {
                return new MediaResult('', '', ['phrase' => $phrase, 'voice_task' => $this->recordId($generation)]);
            }

            return null;
        }
        if ($state !== 'success') {
            return null;
        }

        if ($operation === 'voice_create') {
            return $this->profile($this->recordId($generation), 'voice_persona', $music['name']);
        }
        if ($operation === 'lyrics') {
            $variants = [];
            foreach ($result['resultObject']['lyricsData'] ?? [] as $item) {
                if (is_string($item['text'] ?? null) && trim($item['text']) !== '' && strtoupper($item['status'] ?? 'SUCCESS') === 'SUCCESS') {
                    $variants[] = ['title' => $item['title'] ?? '', 'text' => $item['text']];
                }
            }
            if ($variants) {
                return new MediaResult('', '', ['lyrics' => $variants]);
            }
            throw new RuntimeException('Suno returned no lyrics');
        }
        if ($operation === 'cover') {
            $url = KieProvider::resultUrl($result);
            if (! $url) {
                throw new RuntimeException('Seedream returned no cover');
            }
            $image = ImageNormalizer::normalize($this->download($url, 20_000_000), 2048);

            return new MediaResult($image->bytes, $image->extension, ['cover' => true]);
        }
        if ($operation === 'boost_style') {
            return $this->style($result['resultObject']['result'] ?? $result['result'] ?? '');
        }
        if ($operation === 'wav') {
            $url = $result['resultUrls'][0] ?? '';
            if (! is_string($url) || ! str_starts_with($url, 'https://')) {
                throw new RuntimeException('Suno returned no WAV');
            }

            return new MediaResult('', '', tracks: [['url' => $url, 'title' => $music['name']]]);
        }

        $items = $data['response']['data'] ?? $result['resultObject']['data'] ?? $result['data']['data'] ?? $result['data'] ?? [];
        $tracks = [];
        foreach (is_array($items) ? $items : [] as $item) {
            $url = $item['audio_url'] ?? $item['audioUrl'] ?? null;
            if (is_string($url) && str_starts_with($url, 'https://')) {
                $tracks[] = [
                    'url' => $url,
                    'id' => (string) ($item['id'] ?? ''),
                    'title' => (string) ($item['title'] ?? $music['name'] ?? ''),
                    'duration' => (float) ($item['duration'] ?? 0),
                    'lyrics' => (string) ($item['prompt'] ?? $music['input']['lyrics'] ?? ''),
                    'style' => (string) ($item['tags'] ?? $music['input']['style'] ?? ''),
                ];
            }
        }
        if (! $tracks) {
            foreach ($result['resultUrls'] ?? [] as $url) {
                if (is_string($url) && preg_match('~^https://[^?]+\.(mp3|wav|m4a|ogg|flac|aac)(?:\?|$)~i', $url)) {
                    $title = $music['name'] ?? '';
                    if ($operation === 'stems') {
                        $filename = pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME);
                        $stem = Str::afterLast(rawurldecode($filename), '_');
                        $title .= ' · '.mb_substr($stem, 0, 40);
                    }
                    $tracks[] = ['url' => $url, 'title' => $title];
                }
            }
        }
        if (! $tracks) {
            throw new RuntimeException('Suno returned no audio');
        }

        return new MediaResult('', '', tracks: $tracks);
    }

    public function profile(string $id, string $type, ?string $name): MediaResult
    {
        if ($id === '') {
            throw new RuntimeException('Suno returned no profile ID');
        }

        return new MediaResult('', '', ['profile_id' => $id, 'profile_type' => $type, 'name' => $name]);
    }

    public function style(mixed $value): MediaResult
    {
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException('Suno returned no improved style');
        }

        return new MediaResult('', '', ['style' => mb_substr($value, 0, 1000)]);
    }

    public function audio(string $url): MediaResult
    {
        $bytes = $this->download($url, 80_000_000);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $ext = match ($mime) {
            'audio/mpeg' => 'mp3',
            'audio/wav', 'audio/x-wav', 'audio/vnd.wave' => 'wav',
            'audio/mp4', 'audio/x-m4a', 'video/mp4' => 'm4a',
            'audio/ogg', 'application/ogg' => 'ogg',
            'audio/flac', 'audio/x-flac' => 'flac',
            'audio/aac', 'audio/x-hx-aac' => 'aac',
            default => throw new RuntimeException('Suno returned an invalid audio file'),
        };

        return new MediaResult($bytes, $ext);
    }

    private function download(string $url, int $limit): string
    {
        if (! str_starts_with($url, 'https://')) {
            throw new RuntimeException('Invalid Kie.ai download URL');
        }

        $response = Http::connectTimeout(10)->timeout(70)->withOptions([
            'stream' => true,
            'read_timeout' => 20,
            'allow_redirects' => ['protocols' => ['https'], 'max' => 3],
        ])->get($url)->throw();
        $stream = $response->toPsrResponse()->getBody();
        try {
            $bytes = '';
            while (! $stream->eof() && strlen($bytes) <= $limit) {
                $chunk = $stream->read(65536);
                if ($chunk === '') {
                    break;
                }
                $bytes .= $chunk;
            }
            if ($bytes === '' || ! $stream->eof() || strlen($bytes) > $limit) {
                throw new RuntimeException('The Kie.ai result is empty, incomplete or too large');
            }

            return $bytes;
        } finally {
            $stream->close();
        }
    }
}
