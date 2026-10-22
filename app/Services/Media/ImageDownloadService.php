<?php

namespace App\Services\Media;

use App\Data\ProcessedImage;
use App\Models\VehicleGeneration;
use DOMDocument;
use DOMXPath;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

class ImageDownloadService
{
    public const USER_AGENT = 'DvashopImageImporter/1.0';

    public function __construct(private readonly ImageProcessingService $images) {}

    public function download(string $url, string $profile, string $directory): ProcessedImage
    {
        if ($profile === 'vehicle_image') {
            throw new InvalidArgumentException('Для автоматической фотографии автомобиля нужен контекст поколения: downloadVehicle().');
        }
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

    /** Every candidate is screened before any public file is written. */
    public function downloadVehicle(VehicleGeneration $generation, string $url, string $directory, bool $refresh = false): ProcessedImage
    {
        $quality = app(VehicleImageQuality::class);
        $reasons = [];
        $rejected = false;
        $localWork = false;
        try {
            if (! $this->isHttpUrl($url)) {
                throw new InvalidArgumentException('Некорректный URL фотографии.');
            }
            $resolved = $this->resolvePublicDiskUrl($url, true);
            if ($this->obviousNonPhotoUrl($resolved)) {
                throw new VehicleImageNotFound('Источник указывает на логотип, рекламу или заглушку.');
            }
            $response = $this->fetchVehicle($resolved);
            if (! $response->successful()) {
                throw new VehicleImageNotFound('Источник фотографии вернул HTTP '.$response->status(), outcome: 'absent');
            }
            $pageUrl = $response->effectiveUri() ? (string) $response->effectiveUri() : $resolved;
            $candidates = str_contains(strtolower((string) $response->header('Content-Type')), 'text/html')
                ? $this->vehiclePageImages($response->body(), $pageUrl, $generation) : [$resolved];
            foreach (array_slice($candidates, 0, min(3, (int) config('vehicle_images.max_candidates'))) as $candidate) {
                $localWork = false;
                try {
                    if ($this->obviousNonPhotoUrl($candidate)) {
                        $reasons[] = 'non_photo_url';
                        $rejected = true;

                        continue;
                    }
                    $image = $candidate === $resolved ? $response : $this->fetchVehicle($candidate);
                    if ($image->effectiveUri() && $this->obviousNonPhotoUrl((string) $image->effectiveUri())) {
                        $reasons[] = 'non_photo_redirect';
                        $rejected = true;

                        continue;
                    }
                    $bytes = $image->body();
                    if (! $image->successful()) {
                        $reasons[] = 'HTTP '.$image->status();

                        continue;
                    }
                    $localWork = true;
                    $assessment = $quality->assess($generation, $bytes, refresh: $refresh);
                    if ($assessment['state'] !== 'accepted') {
                        $reasons[] = $assessment['state'].':'.$assessment['reason'];
                        $rejected = true;

                        continue;
                    }
                    $processed = $this->images->processBinary($bytes, basename((string) parse_url($candidate, PHP_URL_PATH)),
                        $image->header('Content-Type'), 'vehicle_image', $directory, $url);
                    try {
                        $quality->inherit($generation, $bytes, Storage::disk($processed->disk)->get($processed->path),
                            ['source_url' => $url, 'page_url' => $pageUrl, 'image_url' => $candidate]);
                    } catch (\Throwable $e) {
                        app(MediaFileCleanupService::class)->deleteProcessedImage($processed);
                        throw $e;
                    }

                    return $processed;
                } catch (\Throwable $e) {
                    if ($refresh && $localWork) {
                        throw $e;
                    }
                    $reasons[] = 'candidate_unavailable_or_invalid';
                    // One failed candidate cannot approve another; every image is verified separately.
                }
            }
        } catch (\Throwable $e) {
            if ($refresh && $localWork) {
                throw $e;
            }
            if ($e instanceof VehicleImageNotFound) {
                throw $e;
            }
            throw new VehicleImageNotFound('Источник не дал подтверждённой фотографии автомобиля.', previous: $e, outcome: 'absent');
        }

        throw new VehicleImageNotFound('Достоверно подходящая фотография не найдена: '.implode(', ', array_unique($reasons ?: ['no_supported_photo_candidates'])), outcome: $rejected ? 'rejected' : 'absent');
    }

    private function fetchVehicle(string $url): Response
    {
        $limit = (int) config('media.max_source_size');
        $response = Http::withUserAgent(self::USER_AGENT)->connectTimeout(3)->timeout(10)
            ->withOptions(['allow_redirects' => ['max' => 3, 'protocols' => ['https', 'http']],
                'progress' => static function ($total, $downloaded) use ($limit): void {
                    if ($total > $limit || $downloaded > $limit) {
                        throw new RuntimeException('Источник фотографии слишком большой.');
                    }
                }])->get($url);
        if (strlen($response->body()) > $limit) {
            throw new RuntimeException('Источник фотографии слишком большой.');
        }

        return $response;
    }

    private function vehiclePageImages(string $html, string $source, VehicleGeneration $g): array
    {
        // Source access policy is separate from relevance. An allowed host never approves pixels.
        if (! in_array(strtolower((string) parse_url($source, PHP_URL_HOST)), ['auto.ru', 'www.drom.ru', 'drom.ru', 'auto.mail.ru', 'wroom.ru', 'quto.ru'], true)) {
            return [];
        }
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new DOMXPath($document);
            $g->loadMissing('model.make');
            $context = mb_strtolower(urldecode($source).' '.$xpath->evaluate('string(//title)').' '.$xpath->evaluate('string(//meta[@property="og:title"]/@content)'));
            $caption = $xpath->evaluate('string(//title)').' '.$xpath->evaluate('string(//meta[@property="og:title"]/@content)');
            if (preg_match('/\b((?:19|20)\d{2})\s*[-–—]\s*((?:19|20)\d{2})\b/u', $caption, $pageYears)
                && preg_match('/\b((?:19|20)\d{2})\s*[-–—]\s*((?:19|20)\d{2})\b/u', (string) $g->years_label, $vehicleYears)
                && ((int) $pageYears[2] < (int) $vehicleYears[1] || (int) $pageYears[1] > (int) $vehicleYears[2])) {
                return [];
            }
            // Generic site/search pages must not lend their social-sharing image to a vehicle.
            $model = mb_strtolower((string) $g->model?->title);
            $make = mb_strtolower((string) $g->model?->make?->title);
            $matches = static fn (string $text, string $term): bool => $term !== ''
                && (bool) preg_match('/(?<![\\pL\\pN])'.preg_quote($term, '/').'(?![\\pL\\pN])/u', $text);
            $pageMatches = $matches($context, $model) && $matches($context, $make);
            $urls = [];
            $walk = function ($node) use (&$walk, &$urls, $pageMatches, $model, $make, $matches): void {
                if (! is_array($node)) {
                    return;
                }
                $description = mb_strtolower(json_encode([$node['name'] ?? '', $node['brand'] ?? '', $node['model'] ?? '', $node['description'] ?? ''], JSON_UNESCAPED_UNICODE));
                if (array_intersect((array) ($node['@type'] ?? []), ['Car', 'Vehicle'])
                    && ($pageMatches || ($matches($description, $model) && $matches($description, $make)) || config('vehicle_images.verifier_url'))) {
                    $images = $node['image'] ?? [];
                    if (is_string($images) || isset($images['url']) || isset($images['contentUrl'])) {
                        $images = [$images];
                    }
                    foreach ((array) $images as $image) {
                        $urls[] = is_array($image) ? ($image['url'] ?? $image['contentUrl'] ?? '') : $image;
                    }
                }
                foreach ($node as $child) {
                    if (is_array($child)) {
                        $walk($child);
                    }
                }
            };
            foreach ($xpath->query('//script[@type="application/ld+json"]') as $node) {
                $walk(json_decode($node->textContent, true));
            }
            $g->loadMissing('model');
            foreach ($xpath->query('//img[@src]') as $node) {
                $alt = mb_strtolower($node->getAttribute('alt'));
                if ($matches($alt, $model) && ($pageMatches || $matches($alt, $make))) {
                    $urls[] = $node->getAttribute('src');
                }
            }
            if ($pageMatches) {
                foreach ($xpath->query('//meta[@property="og:image" or @name="twitter:image"]/@content') as $node) {
                    $urls[] = $node->nodeValue;
                }
            }
            $out = [];
            foreach ($urls as $candidate) {
                if (! is_string($candidate) || trim($candidate) === '') {
                    continue;
                }
                $absolute = (string) UriResolver::resolve(new Uri($source), new Uri($candidate));
                if ($this->isHttpUrl($absolute)) {
                    $out[] = $absolute;
                }
            }

            return array_values(array_unique($out));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function fetch(string $url): Response
    {
        return Http::withUserAgent(self::USER_AGENT)->timeout(20)->retry(2, 300)->get($url);
    }

    private function resolvePublicDiskUrl(string $url, bool $bounded = false): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (! in_array($host, ['yadi.sk', 'disk.yandex.ru', 'disk.yandex.com'], true)) {
            return $url;
        }

        $request = Http::withUserAgent(self::USER_AGENT)->timeout($bounded ? 10 : 20);
        if ($bounded) {
            $request = $request->connectTimeout(3);
        }
        $response = ($bounded ? $request : $request->retry(2, 300))
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

    private function obviousNonPhotoUrl(string $url): bool
    {
        $path = mb_strtolower(urldecode((string) parse_url($url, PHP_URL_PATH)));

        return (bool) preg_match('~(?:^|[/_.-])(logo|favicon|placeholder|no[_-]?photo|no[_-]?image|banner|captcha|advert|sprite|og[_-]?default)(?:[/_.-]|$)~u', $path);
    }
}
