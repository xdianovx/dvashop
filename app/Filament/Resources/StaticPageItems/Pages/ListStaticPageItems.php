<?php

namespace App\Filament\Resources\StaticPageItems\Pages;

use App\Filament\Resources\StaticPageItems\StaticPageItemResource;
use Filament\Resources\Pages\ListRecords;

class ListStaticPageItems extends ListRecords
{
    protected static string $resource = StaticPageItemResource::class;
}
