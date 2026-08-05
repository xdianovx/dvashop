<?php

namespace App\Filament\Resources\SiteNavigationItems\Pages;

use App\Enums\NavigationZone;
use App\Filament\Resources\SiteNavigationItems\SiteNavigationItemResource;
use App\Models\SiteNavigationItem;
use App\Services\Settings\SiteNavigationAdminService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Validation\ValidationException;

class ListSiteNavigationItems extends ListRecords
{
    protected static string $resource = SiteNavigationItemResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    public function mayReorderCurrentZone(): bool
    {
        return $this->selectedZone() instanceof NavigationZone
            && (auth()->user()?->can('reorder', SiteNavigationItem::class) ?? false);
    }

    /** @param array<int|string> $order */
    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        $zone = $this->selectedZone();

        if (! $zone instanceof NavigationZone) {
            throw ValidationException::withMessages([
                'zone' => 'Перед сортировкой выберите одну зону навигации в фильтре.',
            ]);
        }

        app(SiteNavigationAdminService::class)->reorder(
            SiteNavigationItemResource::actor(),
            $zone,
            $order,
        );
    }

    private function selectedZone(): ?NavigationZone
    {
        $value = $this->tableFilters['zone']['value'] ?? null;

        return is_string($value) ? NavigationZone::tryFrom($value) : null;
    }
}
