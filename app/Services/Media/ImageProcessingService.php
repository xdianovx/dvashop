<?php

namespace App\Services\Media;

use App\Data\ProcessedImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class ImageProcessingService
{
    public function processUploadedFile(
        UploadedFile $file,
        string $profile,
        string $directory,
        ?string $originalUrl = null,
    ): ProcessedImage {
        return $this->processBinary(
            contents: (string) file_get_contents($file->getRealPath()),
            originalName: $file->getClientOriginalName(),
            contentType: $file->getMimeType() ?: $file->getClientMimeType(),
            profile: $profile,
            directory: $directory,
            originalUrl: $originalUrl,
        );
    }

    public function processStoredPublicImage(
        string $path,
        string $profile,
        string $directory,
        ?string $originalUrl = null,
        bool $deleteSource = true,
    ): ProcessedImage {
        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            throw new InvalidArgumentException('Файл изображения не найден в public disk: '.$path);
        }

        $contents = $disk->get($path);
        $processed = $this->processBinary(
            contents: $contents,
            originalName: basename($path),
            contentType: $this->mimeFromPath($disk->path($path)),
            profile: $profile,
            directory: $directory,
            originalUrl: $originalUrl,
        );

        if ($deleteSource && $processed->path !== $path && $disk->exists($path)) {
            $disk->delete($path);
        }

        return $processed;
    }

    public function processBinary(
        string $contents,
        string $originalName,
        ?string $contentType,
        string $profile,
        string $directory,
        ?string $originalUrl = null,
    ): ProcessedImage {
        $this->ensureWebpSupport();
        $this->validateSize($contents);

        $mime = $this->detectMime($contents, $contentType);
        $this->validateMime($mime);

        $dimensions = @getimagesizefromstring($contents);
        if ($dimensions === false || empty($dimensions[0]) || empty($dimensions[1])) {
            throw new InvalidArgumentException('Файл не является читаемым растровым изображением.');
        }

        $source = @imagecreatefromstring($contents);
        if (! $source) {
            throw new InvalidArgumentException('Не удалось открыть изображение для обработки.');
        }

        try {
            $source = $this->autoOrient($source, $contents, $mime);

            $profileConfig = $this->profile($profile);
            $main = $this->resizeToFit(
                $source,
                (int) $profileConfig['max_width'],
                (int) $profileConfig['max_height'],
            );

            $uuid = Str::uuid()->toString();
            $directory = trim($directory, '/');
            $path = $directory.'/'.$uuid.'.webp';
            $quality = (int) ($profileConfig['quality'] ?? 82);

            $binary = $this->encodeWebp($main, $quality);
            Storage::disk('public')->put($path, $binary);

            $conversions = $this->generateConversions(
                source: $source,
                directory: $directory.'/conversions',
                uuid: $uuid,
                conversions: $profileConfig['conversions'] ?? [],
            );

            $size = Storage::disk('public')->size($path);

            return new ProcessedImage(
                disk: 'public',
                path: $path,
                width: imagesx($main),
                height: imagesy($main),
                size: (int) $size,
                mime: 'image/webp',
                checksum: hash('sha256', $contents),
                originalUrl: $originalUrl,
                conversions: $conversions === [] ? null : $conversions,
            );
        } finally {
            if (isset($source) && $source instanceof \GdImage) {
                imagedestroy($source);
            }

            if (isset($main) && $main instanceof \GdImage) {
                imagedestroy($main);
            }
        }
    }

    public function isAllowedMime(?string $mime): bool
    {
        return $mime !== null && in_array(strtolower($mime), config('media.allowed_mimes', []), true);
    }

    /** @return array<string, mixed> */
    private function profile(string $profile): array
    {
        $config = config('media.profiles.'.$profile);

        if (! is_array($config)) {
            throw new InvalidArgumentException('Неизвестный профиль обработки изображения: '.$profile);
        }

        return $config;
    }

    private function validateSize(string $contents): void
    {
        $size = strlen($contents);
        $max = (int) config('media.max_source_size', 15 * 1024 * 1024);

        if ($size <= 0) {
            throw new InvalidArgumentException('Файл изображения пустой.');
        }

        if ($size > $max) {
            throw new InvalidArgumentException('Файл изображения больше допустимого размера.');
        }
    }

    private function detectMime(string $contents, ?string $declaredMime): string
    {
        $finfoMime = null;

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_buffer($finfo, $contents);
                finfo_close($finfo);
                $finfoMime = is_string($detected) ? $detected : null;
            }
        }

        $mime = strtolower((string) ($finfoMime ?: $declaredMime));

        if ($mime === 'image/jpg') {
            return 'image/jpeg';
        }

        return $mime;
    }

    private function validateMime(?string $mime): void
    {
        if (! $this->isAllowedMime($mime)) {
            throw new InvalidArgumentException('Недопустимый тип файла изображения: '.($mime ?: 'unknown'));
        }
    }

    private function mimeFromPath(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $mime = mime_content_type($path);

        return is_string($mime) ? $mime : null;
    }

    private function ensureWebpSupport(): void
    {
        if (! extension_loaded('gd') || ! function_exists('imagecreatefromstring') || ! function_exists('imagewebp')) {
            throw new RuntimeException('Для обработки изображений нужен PHP GD с поддержкой WebP. Пересоберите app container.');
        }
    }

    private function autoOrient(\GdImage $source, string $contents, string $mime): \GdImage
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $source;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'dvashop-exif-');
        if ($tmp === false) {
            return $source;
        }

        try {
            file_put_contents($tmp, $contents);
            $exif = @exif_read_data($tmp);
            $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

            $rotated = match ($orientation) {
                3 => imagerotate($source, 180, 0),
                6 => imagerotate($source, -90, 0),
                8 => imagerotate($source, 90, 0),
                default => false,
            };

            if ($rotated instanceof \GdImage) {
                imagedestroy($source);

                return $rotated;
            }

            return $source;
        } finally {
            @unlink($tmp);
        }
    }

    private function resizeToFit(\GdImage $source, int $maxWidth, int $maxHeight): \GdImage
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        $ratio = min(1, $maxWidth / max(1, $sourceWidth), $maxHeight / max(1, $sourceHeight));
        $targetWidth = max(1, (int) floor($sourceWidth * $ratio));
        $targetHeight = max(1, (int) floor($sourceHeight * $ratio));

        if ($targetWidth === $sourceWidth && $targetHeight === $sourceHeight) {
            $copy = imagecreatetruecolor($sourceWidth, $sourceHeight);
            $this->prepareCanvas($copy);
            imagecopy($copy, $source, 0, 0, 0, 0, $sourceWidth, $sourceHeight);

            return $copy;
        }

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        $this->prepareCanvas($target);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

        return $target;
    }

    private function prepareCanvas(\GdImage $image): void
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefilledrectangle($image, 0, 0, imagesx($image), imagesy($image), $transparent);
    }

    private function encodeWebp(\GdImage $image, int $quality): string
    {
        ob_start();
        $ok = imagewebp($image, null, max(1, min(100, $quality)));
        $binary = (string) ob_get_clean();

        if (! $ok || $binary === '') {
            throw new RuntimeException('Не удалось сконвертировать изображение в WebP.');
        }

        return $binary;
    }

    /**
     * @param array<string, array{width:int,height:int,quality?:int}> $conversions
     * @return array<string, array{disk:string,path:string,width:int,height:int,size:int,mime:string}>
     */
    private function generateConversions(\GdImage $source, string $directory, string $uuid, array $conversions): array
    {
        $result = [];

        foreach ($conversions as $name => $settings) {
            if (! is_array($settings)) {
                continue;
            }

            $conversion = $this->resizeToFit(
                $source,
                (int) ($settings['width'] ?? 300),
                (int) ($settings['height'] ?? 300),
            );

            try {
                $path = trim($directory, '/').'/'.$uuid.'_'.$name.'.webp';
                Storage::disk('public')->put($path, $this->encodeWebp($conversion, (int) ($settings['quality'] ?? 80)));

                $result[(string) $name] = [
                    'disk' => 'public',
                    'path' => $path,
                    'width' => imagesx($conversion),
                    'height' => imagesy($conversion),
                    'size' => (int) Storage::disk('public')->size($path),
                    'mime' => 'image/webp',
                ];
            } finally {
                imagedestroy($conversion);
            }
        }

        return $result;
    }
}
