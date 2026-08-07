<?php

namespace App\Services\Storefront;

use App\Enums\NavigationLinkType;
use App\ViewData\Storefront\StorefrontLinkData;
use Illuminate\Support\Facades\Route;

final class StorefrontDestinationResolver
{
    /** @var list<string> */
    private const ALLOWED_ROUTE_NAMES = [
        'home',
        'catalog.index',
        'about',
        'how',
        'payment',
        'faq',
        'partners',
        'cart.show',
    ];

    public function resolve(
        string $title,
        NavigationLinkType|string|null $type,
        ?string $routeName,
        ?string $url,
        bool $openInNewTab,
    ): ?StorefrontLinkData {
        $title = trim($title);

        if ($title === '') {
            return null;
        }

        $type = $type instanceof NavigationLinkType ? $type : NavigationLinkType::tryFrom((string) $type);

        if ($type === NavigationLinkType::Route) {
            $routeName = trim((string) $routeName);

            if (! in_array($routeName, self::ALLOWED_ROUTE_NAMES, true) || ! Route::has($routeName)) {
                return null;
            }

            return new StorefrontLinkData(
                title: $title,
                url: route($routeName),
                openInNewTab: false,
            );
        }

        if ($type !== NavigationLinkType::Url) {
            return null;
        }

        $url = $this->safeExternalUrl($url);

        if ($url === null) {
            return null;
        }

        return new StorefrontLinkData(
            title: $title,
            url: $url,
            openInNewTab: $openInNewTab,
        );
    }

    public function safeExternalUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === ''
            || str_starts_with($url, '//')
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = parse_url($url, PHP_URL_HOST);

        if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || $host === '') {
            return null;
        }

        return $url;
    }
}
