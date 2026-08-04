<?php

namespace App\Filament\Actions;

use App\Models\PartType;
use App\Models\ProductCategory;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Catalog\CatalogStructureAdminService;
use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;

final class CatalogLifecycleActions
{
    public static function toggleActive(): Action
    {
        return Action::make('toggle_active')
            ->label(fn (Model $record): string => $record->is_active ? 'Деактивировать' : 'Активировать')
            ->icon(fn (Model $record): string => $record->is_active ? 'heroicon-o-pause' : 'heroicon-o-play')
            ->color(fn (Model $record): string => $record->is_active ? 'warning' : 'success')
            ->requiresConfirmation()
            ->modalDescription(fn (Model $record): string => self::usageDescription($record))
            ->authorize(fn (Model $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->action(fn (Model $record) => app(CatalogStructureAdminService::class)->setActive($record, ! $record->is_active));
    }

    private static function usageDescription(Model $record): string
    {
        return match (true) {
            $record instanceof ProductCategory => sprintf(
                'Связи не удаляются. Использование: товары — %d, типы деталей — %d, дочерние категории — %d.',
                $record->products_count ?? $record->products()->count(),
                $record->part_types_count ?? $record->partTypes()->count(),
                $record->children_count ?? $record->children()->count(),
            ),
            $record instanceof PartType => sprintf(
                'Связи не удаляются. Использование: товары — %d, шаблоны — %d, дочерние типы — %d.',
                $record->products_count ?? $record->products()->count(),
                $record->option_templates_count ?? $record->optionTemplates()->count(),
                $record->children_count ?? $record->children()->count(),
            ),
            $record instanceof VehicleMake => sprintf(
                'Связи не удаляются. Моделей марки: %d.',
                $record->models_count ?? $record->models()->count(),
            ),
            $record instanceof VehicleModel => sprintf(
                'Связи не удаляются. Поколений модели: %d.',
                $record->generations_count ?? $record->generations()->count(),
            ),
            $record instanceof VehicleGeneration => sprintf(
                'Связи не удаляются. Применяемостей товаров: %d.',
                $record->fitments_count ?? $record->fitments()->count(),
            ),
            default => 'Связи каталога не удаляются и не изменяются.',
        };
    }
}
