<?php

namespace App\Services\Generation;

/**
 * Providers return anything from a 6 MB PNG to a WebP. The app and Telegram both work better with a
 * JPEG of sane size, so a result is downscaled and re-encoded; without GD the original is kept.
 */
final class ImageNormalizer
{
    public static function normalize(string $bytes, int $maxSide = 2048): MediaResult
    {
        $info = @getimagesizefromstring($bytes);
        $ext = match ($info['mime'] ?? '') {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'png',
        };

        if (! $info || ! function_exists('imagecreatefromstring')) {
            return new MediaResult($bytes, $ext);
        }

        $img = @imagecreatefromstring($bytes);
        if (! $img) {
            return new MediaResult($bytes, $ext);
        }

        [$w, $h] = $info;
        if (max($w, $h) > $maxSide) {
            $scale = $maxSide / max($w, $h);
            $img = imagescale($img, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))) ?: $img;
        }

        // flatten transparency onto white before JPEG
        $flat = imagecreatetruecolor(imagesx($img), imagesy($img));
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));

        ob_start();
        imagejpeg($flat, null, 88);
        $jpeg = (string) ob_get_clean();

        return $jpeg !== '' ? new MediaResult($jpeg, 'jpg') : new MediaResult($bytes, $ext);
    }
}
