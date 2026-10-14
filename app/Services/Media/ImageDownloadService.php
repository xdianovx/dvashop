<?php

namespace App\Services\Media;

use App\Data\ProcessedImage;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class ImageDownloadService
{
    public const USER_AGENT = 'DvashopImageImporter/1.0';

    public function __construct(private readonly ImageProcessingService $images) {}

    public function download(string $url, string $profile, string $directory): ProcessedImage
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Некорректный URL изображения.');
        }

        $downloadUrl = $this->resolvePublicDiskUrl($url);
        $response = $this->fetch($downloadUrl);
        if (str_contains((string) $response->header('Content-Type'), 'text/html')) {
            $downloadUrl = $this->pageImageUrl($response->body(), $url);
            $response = $this->fetch($downloadUrl);
        }

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
            originalName: basename(parse_url($downloadUrl, PHP_URL_PATH) ?: 'remote-image'),
            contentType: is_string($contentType) ? $contentType : null,
            profile: $profile,
            directory: $directory,
            originalUrl: $url,
        );
    }

    private function fetch(string $url): Response
    {
        return Http::withUserAgent(self::USER_AGENT)->timeout(20)->retry(2, 300)->get($url);
    }

    private function resolvePublicDiskUrl(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (! in_array($host, ['yadi.sk', 'disk.yandex.ru', 'disk.yandex.com'], true)) {
            return $url;
        }

        $response = Http::withUserAgent(self::USER_AGENT)->timeout(20)->retry(2, 300)
            ->get('https://cloud-api.yandex.net/v1/disk/public/resources/download', ['public_key' => $url]);
        $href = $response->json('href');
        if (! $response->successful() || ! is_string($href) || ! $this->isHttpUrl($href)) {
            throw new RuntimeException('Не удалось получить файл по публичной ссылке Яндекс Диска.');
        }

        return $href;
    }

    private function pageImageUrl(string $html, string $source): string
    {
        $host = strtolower((string) parse_url($source, PHP_URL_HOST));
        if (! in_array($host, ['auto.ru', 'www.drom.ru', 'drom.ru', 'auto.mail.ru', 'wroom.ru', 'quto.ru'], true)) {
            throw new InvalidArgumentException('Ссылка ведёт на страницу, а не на изображение.');
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $image = (new DOMXPath($document))->evaluate('string(//meta[@property="og:image"]/@content)');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! is_string($image) || ! $this->isHttpUrl($image)) {
            throw new RuntimeException('На странице автомобиля не найдена ссылка на изображение.');
        }

        return $image;
    }

    private function isHttpUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
