<?php

namespace App\Filament\Resources\SiteNavigationItems\Pages;

use App\Filament\Resources\SiteNavigationItems\SiteNavigationItemResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSiteNavigationItem extends ViewRecord
{
    protected static string $resource = SiteNavigationItemResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
