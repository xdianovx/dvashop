<?php

namespace App\Filament\Resources\HomepageQuickLinks\Pages;

use App\Filament\Resources\HomepageQuickLinks\HomepageQuickLinkResource;
use App\Services\Homepage\HomepageContentAdminService;
use Filament\Resources\Pages\ListRecords;

class ListHomepageQuickLinks extends ListRecords
{
    protected static string $resource = HomepageQuickLinkResource::class;

    /** @param array<int|string> $order */
    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        app(HomepageContentAdminService::class)->reorderQuickLinks(HomepageQuickLinkResource::actor(), $order);
    }
}
