<?php

namespace App\Filament\Resources\HomepageMetrics\Pages;

use App\Filament\Resources\HomepageMetrics\HomepageMetricResource;
use App\Services\Homepage\HomepageContentAdminService;
use Filament\Resources\Pages\ListRecords;

class ListHomepageMetrics extends ListRecords
{
    protected static string $resource = HomepageMetricResource::class;

    /** @param array<int|string> $order */
    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        app(HomepageContentAdminService::class)->reorderMetrics(HomepageMetricResource::actor(), $order);
    }
}
