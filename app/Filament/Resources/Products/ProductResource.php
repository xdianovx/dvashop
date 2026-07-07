<?php

namespace App\Filament\Resources\Products;

use App\Enums\ProductStatus;
use App\Enums\StockStatus;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\RelationManagers\ImagesRelationManager;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\VehicleGeneration;
use App\Services\Media\ProductGalleryService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return 'Каталог';
    }

    public static function getModelLabel(): string
    {
        return 'товар';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Товары';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Product tabs')
                ->tabs([
                    Tab::make('Основное')
                        ->schema([
                            TextInput::make('title')
                                ->label('Название')
                                ->required()
                                ->maxLength(255),
                            TextInput::make('slug')
                                ->label('Slug')
                                ->maxLength(255)
                                ->unique(ignoreRecord: true)
                                ->helperText('Можно оставить пустым — будет создан из названия.'),
                            TextInput::make('sku')
                                ->label('SKU товара')
                                ->maxLength(255)
                                ->unique(ignoreRecord: true),
                            Select::make('product_category_id')
                                ->label('Основная категория')
                                ->searchable()
                                ->preload()
                                ->options(fn (): array => ProductCategory::query()
                                    ->orderBy('full_slug')
                                    ->get()
                                    ->mapWithKeys(fn (ProductCategory $category): array => [
                                        $category->getKey() => $category->display_title.' · '.$category->full_slug,
                                    ])
                                    ->all())
                                ->nullable(),
                            Select::make('status')
                                ->label('Статус')
                                ->options(ProductStatus::options())
                                ->default(ProductStatus::Draft->value)
                                ->required(),
                            TextInput::make('position')
                                ->label('Позиция')
                                ->numeric()
                                ->default(0)
                                ->required(),
                            Toggle::make('is_featured')
                                ->label('Рекомендуемый')
                                ->default(false),
                            Textarea::make('short_description')
                                ->label('Краткое описание')
                                ->rows(3)
                                ->columnSpanFull(),
                            Textarea::make('description')
                                ->label('Описание')
                                ->rows(6)
                                ->columnSpanFull(),
                            TextInput::make('import_key')
                                ->label('Import key')
                                ->maxLength(255)
                                ->unique(ignoreRecord: true),
                            TextInput::make('import_source')
                                ->label('Import source')
                                ->maxLength(255),
                            TextInput::make('last_import_run_id')
                                ->label('Last import run ID')
                                ->maxLength(255),
                        ])
                        ->columns(2),
                    Tab::make('Цена/наличие')
                        ->schema([
                            TextInput::make('price')
                                ->label('Цена')
                                ->numeric()
                                ->prefix('₽'),
                            TextInput::make('old_price')
                                ->label('Старая цена')
                                ->numeric()
                                ->prefix('₽'),
                            Select::make('stock_status')
                                ->label('Наличие')
                                ->options(StockStatus::options())
                                ->default(StockStatus::InStock->value)
                                ->required(),
                            Repeater::make('variants')
                                ->label('Варианты товара')
                                ->relationship()
                                ->schema([
                                    TextInput::make('sku')
                                        ->label('SKU варианта')
                                        ->maxLength(255)
                                        ->unique(ignoreRecord: true),
                                    TextInput::make('title')
                                        ->label('Название варианта')
                                        ->maxLength(255),
                                    KeyValue::make('options')
                                        ->label('Опции')
                                        ->keyLabel('Опция')
                                        ->valueLabel('Значение'),
                                    TextInput::make('price')
                                        ->label('Цена')
                                        ->numeric()
                                        ->prefix('₽')
                                        ->required(),
                                    TextInput::make('old_price')
                                        ->label('Старая цена')
                                        ->numeric()
                                        ->prefix('₽'),
                                    TextInput::make('stock_quantity')
                                        ->label('Остаток')
                                        ->numeric(),
                                    Select::make('stock_status')
                                        ->label('Наличие')
                                        ->options(StockStatus::options())
                                        ->default(StockStatus::InStock->value)
                                        ->required(),
                                    Toggle::make('is_default')
                                        ->label('Default')
                                        ->default(false),
                                    Toggle::make('is_active')
                                        ->label('Активен')
                                        ->default(true),
                                ])
                                ->defaultItems(1)
                                ->columns(3)
                                ->addActionLabel('Добавить вариант')
                                ->columnSpanFull(),
                        ])
                        ->columns(2),
                    Tab::make('Галерея')
                        ->schema([
                            TextInput::make('gallery_help')
                                ->label('Изображения товара')
                                ->default('Галерея редактируется в блоке «Изображения» на странице товара: можно загрузить ручные изображения, выбрать главное, менять видимость, порядок и сбрасывать к дефолтному.')
                                ->disabled()
                                ->dehydrated(false)
                                ->columnSpanFull(),
                        ]),
                    Tab::make('Применимость')
                        ->schema([
                            Repeater::make('fitments')
                                ->label('Поколения авто')
                                ->relationship()
                                ->schema([
                                    Select::make('vehicle_generation_id')
                                        ->label('Поколение')
                                        ->searchable()
                                        ->preload()
                                        ->options(fn (): array => VehicleGeneration::query()
                                            ->with('model.make')
                                            ->get()
                                            ->sortBy('display_title')
                                            ->mapWithKeys(fn (VehicleGeneration $generation): array => [
                                                $generation->getKey() => $generation->display_title,
                                            ])
                                            ->all())
                                        ->required(),
                                    TextInput::make('note')
                                        ->label('Примечание')
                                        ->maxLength(255),
                                    Toggle::make('is_primary')
                                        ->label('Основная применимость')
                                        ->default(false),
                                ])
                                ->columns(3)
                                ->addActionLabel('Добавить применимость')
                                ->columnSpanFull(),
                        ]),
                    Tab::make('SEO')
                        ->schema([
                            TextInput::make('meta_title')
                                ->label('Meta title')
                                ->maxLength(255),
                            Textarea::make('meta_description')
                                ->label('Meta description')
                                ->rows(3)
                                ->columnSpanFull(),
                        ]),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->columns([
                ImageColumn::make('main_image_url')
                    ->label('Фото')
                    ->getStateUsing(fn (Product $record): string => $record->main_image_url)
                    ->square()
                    ->toggleable(),
                TextColumn::make('title')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('category.full_title')
                    ->label('Категория')
                    ->state(fn (Product $record): string => $record->category?->full_title ?? '—')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('category', function (Builder $categoryQuery) use ($search): void {
                            $categoryQuery
                                ->where('title', 'like', '%'.$search.'%')
                                ->orWhere('full_slug', 'like', '%'.$search.'%');
                        });
                    }),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (ProductStatus | string | null $state): string => $state instanceof ProductStatus ? $state->label() : (ProductStatus::tryFrom((string) $state)?->label() ?? '—')),
                TextColumn::make('price')
                    ->label('Цена')
                    ->sortable(),
                TextColumn::make('stock_status')
                    ->label('Наличие')
                    ->badge()
                    ->formatStateUsing(fn (StockStatus | string | null $state): string => $state instanceof StockStatus ? $state->label() : (StockStatus::tryFrom((string) $state)?->label() ?? '—')),
                TextColumn::make('variants_count')
                    ->label('Варианты')
                    ->counts('variants')
                    ->sortable(),
                IconColumn::make('is_featured')
                    ->label('Реком.')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->label('Обновлено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(ProductStatus::options()),
                SelectFilter::make('stock_status')
                    ->label('Наличие')
                    ->options(StockStatus::options()),
                TernaryFilter::make('is_featured')
                    ->label('Рекомендуемый'),
                Filter::make('without_images')
                    ->label('Без изображения')
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
                SelectFilter::make('product_category_id')
                    ->label('Категория')
                    ->options(fn (): array => ProductCategory::query()
                        ->orderBy('full_slug')
                        ->get()
                        ->mapWithKeys(fn (ProductCategory $category): array => [
                            $category->getKey() => $category->full_title,
                        ])
                        ->all()),
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
                    ->url(fn (Product $record): string => self::getUrl('edit', ['record' => $record])),
                Action::make('make_default_main')
                    ->label('Дефолтное главным')
                    ->icon('heroicon-o-star')
                    ->requiresConfirmation()
                    ->action(fn (Product $record): ProductImage => app(ProductGalleryService::class)->makeDefaultMain($record)),
                Action::make('reset_gallery_to_default')
                    ->label('Сбросить к дефолтной')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Сбросить галерею к дефолтному изображению?')
                    ->modalDescription('Будут удалены все ручные и импортные изображения товара вместе с файлами. Файл из public/img/products_default не удаляется.')
                    ->action(fn (Product $record): ProductImage => app(ProductGalleryService::class)->resetToDefault($record)),
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['category.parent', 'mainImage', 'images'])
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ImagesRelationManager::class,
        ];
    }

    public static function applyWithoutVisibleImagesFilter(Builder $query): Builder
    {
        return $query->whereDoesntHave('images', fn (Builder $imageQuery): Builder => $imageQuery->where('is_visible', true));
    }

    public static function applyImageSourceFilter(Builder $query, string $sourceType): Builder
    {
        return $query->whereHas('images', fn (Builder $imageQuery): Builder => $imageQuery->where('source_type', $sourceType));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
