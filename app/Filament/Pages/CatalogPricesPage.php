<?php

namespace App\Filament\Pages;

use App\Services\Catalog\CatalogBulkPrices;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** The two administrative operations live in one confirmed, audited workflow. */
class CatalogPricesPage extends Page
{
    protected string $view = 'filament.pages.catalog-prices';

    // Keep the existing URL: bookmarks and sidebar links must not break.
    protected static ?string $slug = 'catalog/prices';

    public static function getNavigationGroup(): ?string
    {
        return 'Каталог';
    }

    public static function getNavigationLabel(): string
    {
        return 'Массовое управление товарами';
    }

    public function getTitle(): string
    {
        return 'Массовое управление товарами';
    }

    public static function canAccess(): bool
    {
        try {
            app(CatalogBulkPrices::class)->authorize(Filament::auth()->user());

            return true;
        } catch (HttpException $e) {
            return false;
        }
    }
}
