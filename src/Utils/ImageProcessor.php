<?php

namespace src\Utils;

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
            return @move_uploaded_file($tmpPath, $destPath);
        }

        $info = @getimagesize($tmpPath);
        if (!is_array($info) || empty($info[0]) || empty($info[1])) {
            return @move_uploaded_file($tmpPath, $destPath);
        }

        $width = (int) $info[0];
        $height = (int) $info[1];
        if ($width <= 0 || $height <= 0) {
            return @move_uploaded_file($tmpPath, $destPath);
        }

        $scale = min($maxWidth / $width, $maxHeight / $height, 1);
        $newWidth = (int) max(1, round($width * $scale));
        $newHeight = (int) max(1, round($height * $scale));

        $src = self::createSourceImage($tmpPath, $mime);
        if (!$src) {
            return self::fallbackMove($tmpPath, $destPath);
        }

        $dst = imagecreatetruecolor($newWidth, $newHeight);
        if (!$dst) {
            // @intelephense-ignore-next-line
            imagedestroy($src);
            return self::fallbackMove($tmpPath, $destPath);
        }

        if (in_array($mime, ['image/png', 'image/webp'], true)) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $newWidth, $newHeight, $transparent);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        $saved = self::saveImage($dst, $destPath, $mime, $quality);

        if (!$saved) {
            // @intelephense-ignore-next-line
            imagedestroy($src);
            // @intelephense-ignore-next-line
            imagedestroy($dst);
            return self::fallbackMove($tmpPath, $destPath);
        }

        // @intelephense-ignore-next-line
        imagedestroy($src);
        // @intelephense-ignore-next-line
        imagedestroy($dst);
        return true;
    }

    private static function createSourceImage(string $tmpPath, string $mime)
    {
        return match ($mime) {
            'image/jpeg', 'image/jpg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($tmpPath) : null,
            'image/png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($tmpPath) : null,
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpPath) : null,
            default => null,
        };
    }

    private static function saveImage($resource, string $destPath, string $mime, int $quality): bool
    {
        return match ($mime) {
            'image/jpeg', 'image/jpg' => @imagejpeg($resource, $destPath, max(60, min(90, $quality))),
            'image/png' => @imagepng($resource, $destPath, 6),
            'image/webp' => function_exists('imagewebp')
                ? @imagewebp($resource, $destPath, max(60, min(90, $quality)))
                : false,
            default => false,
        };
    }

    private static function fallbackMove(string $tmpPath, string $destPath): bool
    {
        if (@move_uploaded_file($tmpPath, $destPath)) {
            return true;
        }

        return @copy($tmpPath, $destPath);
    }
}
