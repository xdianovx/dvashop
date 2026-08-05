<?php

namespace App\Filament\Resources\HomepageCategoryCards\Pages;

use App\Filament\Resources\HomepageCategoryCards\HomepageCategoryCardResource;
use App\Services\Homepage\HomepageContentAdminService;
use Filament\Resources\Pages\ListRecords;

class ListHomepageCategoryCards extends ListRecords
{
    protected static string $resource = HomepageCategoryCardResource::class;

    /** @param array<int|string> $order */
    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        app(HomepageContentAdminService::class)->reorderCategoryCards(HomepageCategoryCardResource::actor(), $order);
    }
}
