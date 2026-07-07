<?php

namespace App\Services\Media;

use App\Data\ProcessedImage;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class ImageDownloadService
{
    public function __construct(private readonly ImageProcessingService $images) {}

    public function download(string $url, string $profile, string $directory): ProcessedImage
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Некорректный URL изображения.');
        }

        $response = Http::timeout(20)->retry(2, 300)->get($url);

        if (! $response->successful()) {
            throw new RuntimeException('Не удалось скачать изображение. HTTP '.$response->status());
        }

        $contentType = $response->header('Content-Type');
        if (is_string($contentType) && str_contains($contentType, ';')) {
            $contentType = trim(strtok($contentType, ';'));
        }

        $body = $response->body();

        return $this->images->processBinary(
            contents: $body,
            originalName: basename(parse_url($url, PHP_URL_PATH) ?: 'remote-image'),
            contentType: is_string($contentType) ? $contentType : null,
            profile: $profile,
            directory: $directory,
            originalUrl: $url,
        );
    }
}
