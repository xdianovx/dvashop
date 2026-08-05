<?php

namespace App\Filament\Resources\FaqCategories\Pages;

use App\Filament\Resources\FaqCategories\FaqCategoryResource;
use App\Services\StaticContent\FaqAdminService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFaqCategories extends ListRecords
{
    protected static string $resource = FaqCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    /** @param array<int|string> $order */
    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        app(FaqAdminService::class)->reorderCategories(FaqCategoryResource::actor(), $order);
    }
}
