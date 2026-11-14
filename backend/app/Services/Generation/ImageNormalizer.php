<?php

namespace App\Services\Generation;

/**
 * Re-encodes pictures with GD. Results: providers return anything from a 6 MB PNG to a WebP, the app and
 * Telegram both work better with a JPEG of sane size. Uploads: decoding and re-encoding drops metadata
 * and anything hidden inside the file, fixes phone rotation and keeps the size within provider limits.
 * Without GD the original bytes are kept.
 */
final class ImageNormalizer
{
    public static function normalize(string $bytes, int $maxSide = 2048, bool $keepAlpha = false, ?int $orientation = null): MediaResult
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

        // A decompression bomb is refused before GD allocates memory for it.
        if ($info[0] * $info[1] > 80_000_000) {
            return new MediaResult($bytes, $ext);
        }

        $img = @imagecreatefromstring($bytes);
        if (! $img) {
            return new MediaResult($bytes, $ext);
        }

        $img = self::rotate($img, $orientation);
        $w = imagesx($img);
        $h = imagesy($img);
        if (max($w, $h) > $maxSide) {
            $scale = $maxSide / max($w, $h);
            $img = imagescale($img, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))) ?: $img;
        }

        if ($keepAlpha) {
            imagealphablending($img, false);
            imagesavealpha($img, true);
            ob_start();
            imagepng($img, null, 6);
            $png = (string) ob_get_clean();

            return $png !== '' ? new MediaResult($png, 'png') : new MediaResult($bytes, $ext);
        }

        // flatten transparency onto white before JPEG
        $flat = imagecreatetruecolor(imagesx($img), imagesy($img));
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));

        ob_start();
        imagejpeg($flat, null, 90);
        $jpeg = (string) ob_get_clean();

        return $jpeg !== '' ? new MediaResult($jpeg, 'jpg') : new MediaResult($bytes, $ext);
    }

    /** An uploaded photo: rotation from EXIF applied, metadata stripped, at most 2048 px. */
    public static function upload(string $path): MediaResult
    {
        $orientation = null;
        if (function_exists('exif_read_data') && @exif_imagetype($path) === IMAGETYPE_JPEG) {
            $orientation = (int) (@exif_read_data($path)['Orientation'] ?? 0) ?: null;
        }

        return self::normalize((string) file_get_contents($path), 2048, false, $orientation);
    }

    private static function rotate(\GdImage $img, ?int $orientation): \GdImage
    {
        $angle = match ($orientation) { 3 => 180, 6 => -90, 8 => 90, default => 0 };

        return $angle ? (imagerotate($img, $angle, 0) ?: $img) : $img;
    }
}
