<?php

namespace App\Filament\Resources\Products\Tables;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Enums\StockStatus;
use App\Filament\Resources\Products\Actions\ProductGalleryActions;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductImage;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->columns([
                ImageColumn::make('main_image_url')
                    ->label('Фото')
                    ->getStateUsing(fn (Product $record): string => $record->main_image_url)
                    ->square(),
                TextColumn::make('title')
                    ->label('Название')
                    ->searchable()
                    ->sortable()
                    ->limit(48)
                    ->tooltip(fn (?string $state): ?string => is_string($state) && mb_strlen($state) > 48 ? $state : null),
                TextColumn::make('product_type')
                    ->label('Тип товара')
                    ->badge()
                    ->formatStateUsing(fn (ProductType|string|null $state): string => $state instanceof ProductType
                        ? $state->label()
                        : (ProductType::tryFrom((string) $state)?->label() ?? '—')),
                TextColumn::make('category.full_title')
                    ->label('Категория магазина')
                    ->state(fn (Product $record): string => $record->category?->full_title ?? '—')
                    ->wrap()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('category', function (Builder $categoryQuery) use ($search): void {
                            $categoryQuery
                                ->where('title', 'like', '%'.$search.'%')
                                ->orWhere('full_slug', 'like', '%'.$search.'%');
                        });
                    }),
                TextColumn::make('partType.full_title')
                    ->label('Тип детали')
                    ->placeholder('—')
                    ->wrap()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('partType', function (Builder $partTypeQuery) use ($search): void {
                            $partTypeQuery
                                ->where('title', 'like', '%'.$search.'%')
                                ->orWhere('full_title', 'like', '%'.$search.'%')
                                ->orWhere('full_slug', 'like', '%'.$search.'%');
                        });
                    }),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (ProductStatus|string|null $state): string => $state instanceof ProductStatus
                        ? $state->label()
                        : (ProductStatus::tryFrom((string) $state)?->label() ?? '—')),
                TextColumn::make('price')
                    ->label('Цена')
                    ->money('RUB')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('stock_status')
                    ->label('Наличие')
                    ->badge()
                    ->formatStateUsing(fn (StockStatus|string|null $state): string => $state instanceof StockStatus
                        ? $state->label()
                        : (StockStatus::tryFrom((string) $state)?->label() ?? '—')),
                TextColumn::make('images_count')
                    ->label('Изображения')
                    ->badge()
                    ->sortable(),
                TextColumn::make('image_sources')
                    ->label('Источники фото')
                    ->state(fn (Product $record): array => $record->images
                        ->pluck('source_type')
                        ->filter()
                        ->unique()
                        ->values()
                        ->all())
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ProductImage::sourceTypeLabel($state))
                    ->color(fn (?string $state): string => ProductImage::sourceTypeColor($state))
                    ->placeholder('—'),
                TextColumn::make('meta_title')
                    ->label('Meta title')
                    ->limit(48)
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('noindex')
                    ->label('Noindex')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Обновлено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('product_type')
                    ->label('Тип товара')
                    ->options([
                        ProductType::AutoPart->value => ProductType::AutoPart->label(),
                        ProductType::Generic->value => ProductType::Generic->label(),
                    ]),
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(ProductStatus::options()),
                SelectFilter::make('product_category_id')
                    ->label('Категория магазина')
                    ->searchable()
                    ->preload()
                    ->options(ProductForm::productCategoryOptions(...)),
                SelectFilter::make('part_type_id')
                    ->label('Тип детали')
                    ->searchable()
                    ->preload()
                    ->options(fn (): array => PartType::query()->orderBy('full_slug')->pluck('full_title', 'id')->all()),
                Filter::make('without_images')
                    ->label('Без изображений')
                    ->query(fn (Builder $query): Builder => self::applyWithoutVisibleImagesFilter($query)),
                Filter::make('with_default_image')
                    ->label('С дефолтным изображением')
                    ->query(fn (Builder $query): Builder => self::applyImageSourceFilter($query, ProductImage::SOURCE_DEFAULT)),
                Filter::make('with_manual_image')
                    ->label('С ручным изображением')
                    ->query(fn (Builder $query): Builder => self::applyImageSourceFilter($query, ProductImage::SOURCE_MANUAL)),
                Filter::make('with_import_image')
                    ->label('С импортным изображением')
                    ->query(fn (Builder $query): Builder => self::applyImageSourceFilter($query, ProductImage::SOURCE_IMPORT)),
                TernaryFilter::make('noindex')
                    ->label('Индексация')
                    ->trueLabel('Только noindex')
                    ->falseLabel('Только индексируемые'),
                SelectFilter::make('import_source')
                    ->label('Источник импорта')
                    ->options(fn (): array => Product::query()
                        ->whereNotNull('import_source')
                        ->where('import_source', '!=', '')
                        ->distinct()
                        ->orderBy('import_source')
                        ->pluck('import_source', 'import_source')
                        ->all()),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('gallery')
                    ->label('Галерея')
                    ->icon('heroicon-o-photo')
                    ->url(fn (Product $record): string => ProductResource::getUrl('edit', [
                        'record' => $record,
                        'product-tab' => 'product-gallery-tab',
                    ])),
                ProductGalleryActions::makeDefaultMain('table_make_default_main'),
                ProductGalleryActions::resetToDefault('table_reset_gallery_to_default'),
                DeleteAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function applyWithoutVisibleImagesFilter(Builder $query): Builder
    {
        return $query->whereDoesntHave('images', fn (Builder $imageQuery): Builder => $imageQuery->where('is_visible', true));
    }

    public static function applyImageSourceFilter(Builder $query, string $sourceType): Builder
    {
        return $query->whereHas('images', fn (Builder $imageQuery): Builder => $imageQuery->where('source_type', $sourceType));
    }
}
