<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Feeds\YandexFeedState;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class YandexFeedController extends Controller
{
    public function __invoke(Request $request, YandexFeedState $state): StreamedResponse
    {
        return $this->response($request, $state->path(), 'application/xml; charset=UTF-8');
    }

    public function gzip(Request $request, YandexFeedState $state): StreamedResponse
    {
        return $this->response($request, $state->gzipPath(), 'application/gzip');
    }

    private function response(Request $request, string $path, string $contentType): StreamedResponse
    {
        abort_unless(config('yandex-feed.enabled'), 503, 'Feed is disabled.');
        abort_unless(is_file($path), 503, 'Feed has not been generated yet.');
        $file = fopen($path, 'rb');
        abort_if($file === false, 503, 'Feed is temporarily unavailable.');
        $stat = fstat($file);
        if ($stat === false) {
            fclose($file);
            abort(503, 'Feed is temporarily unavailable.');
        }

        // The descriptor pins one immutable published inode across atomic rename.
        // A weak generation validator from its metadata is O(1); only a 200 body
        // invokes fpassthru(), so HEAD and conditional 304 never scan the feed.
        $etag = hash('sha256', implode(':', [
            $stat['dev'], $stat['ino'], $stat['size'], $stat['mtime'], $stat['ctime'],
        ]));
        $response = new StreamedResponse(function () use ($file): void {
            try {
                fpassthru($file);
            } finally {
                fclose($file);
            }
        }, 200, ['Content-Type' => $contentType]);
        $response->setPublic()->setMaxAge(0)->setEtag($etag, true);
        $response->headers->addCacheControlDirective('must-revalidate');
        $response->setLastModified(new DateTimeImmutable('@'.$stat['mtime']));
        if ($response->isNotModified($request)) {
            fclose($file);
            $response->setCallback(static function (): void {});
        } else {
            $response->headers->set('Content-Length', (string) $stat['size']);
            if ($request->isMethod('HEAD')) {
                fclose($file);
                $response->setCallback(static function (): void {});
            }
        }

        return $response;
    }
}
