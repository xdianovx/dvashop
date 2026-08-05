<?php

namespace App\Filament\Resources\StaticPages\Pages;

use App\Filament\Resources\StaticPages\StaticPageResource;
use App\Services\StaticContent\StaticPageContentAdminService;
use Filament\Resources\Pages\ListRecords;

class ListStaticPages extends ListRecords
{
    protected static string $resource = StaticPageResource::class;

    /** @param array<int|string> $order */
    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        app(StaticPageContentAdminService::class)->reorderPages(StaticPageResource::actor(), $order);
    }
}
