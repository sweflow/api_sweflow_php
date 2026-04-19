<?php

namespace Src\Kernel\Utils;

use GdImage;

class ImageProcessor
{
    public static function resizeAndSave(
        string $tmpPath,
        string $destPath,
        string $mime,
        int $maxWidth,
        int $maxHeight,
        int $quality = 82
    ): bool {
        if (!is_file($tmpPath)) {
            return false;
        }

        if (!function_exists('imagecreatetruecolor')) {
            return self::fallbackMove($tmpPath, $destPath);
        }

        if (!function_exists('getimagesize')) {
            return (bool) @move_uploaded_file($tmpPath, $destPath);
        }

        $info = @getimagesize($tmpPath);
        if (!is_array($info) || empty($info[0]) || empty($info[1])) {
            return (bool) @move_uploaded_file($tmpPath, $destPath);
        }

        $width  = (int) $info[0];
        $height = (int) $info[1];

        $scale     = min($maxWidth / $width, $maxHeight / $height, 1.0);
        $newWidth  = (int) max(1, round($width * $scale));
        $newHeight = (int) max(1, round($height * $scale));

        $src = self::createSourceImage($tmpPath, $mime);
        if ($src === null) {
            return self::fallbackMove($tmpPath, $destPath);
        }

        $dst = imagecreatetruecolor($newWidth, $newHeight);
        if ($dst === false) {
            imagedestroy($src);
            return self::fallbackMove($tmpPath, $destPath);
        }

        if (in_array($mime, ['image/png', 'image/webp'], true)) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            if ($transparent !== false) {
                imagefilledrectangle($dst, 0, 0, $newWidth, $newHeight, $transparent);
            }
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        $saved = self::saveImage($dst, $destPath, $mime, $quality);

        imagedestroy($src);
        imagedestroy($dst);

        if (!$saved) {
            return self::fallbackMove($tmpPath, $destPath);
        }

        return true;
    }

    private static function createSourceImage(string $tmpPath, string $mime): ?GdImage
    {
        $result = match ($mime) {
            'image/jpeg', 'image/jpg' => function_exists('imagecreatefromjpeg')
                ? @imagecreatefromjpeg($tmpPath)
                : false,
            'image/png'  => function_exists('imagecreatefrompng')
                ? @imagecreatefrompng($tmpPath)
                : false,
            'image/webp' => function_exists('imagecreatefromwebp')
                ? @imagecreatefromwebp($tmpPath)
                : false,
            default => false,
        };

        return ($result instanceof GdImage) ? $result : null;
    }

    private static function saveImage(GdImage $resource, string $destPath, string $mime, int $quality): bool
    {
        return match ($mime) {
            'image/jpeg', 'image/jpg' => (bool) @imagejpeg($resource, $destPath, max(60, min(90, $quality))),
            'image/png'               => (bool) @imagepng($resource, $destPath, 6),
            'image/webp'              => function_exists('imagewebp')
                ? (bool) @imagewebp($resource, $destPath, max(60, min(90, $quality)))
                : false,
            default => false,
        };
    }

    private static function fallbackMove(string $tmpPath, string $destPath): bool
    {
        if (@move_uploaded_file($tmpPath, $destPath)) {
            return true;
        }
        return (bool) @copy($tmpPath, $destPath);
    }
}
