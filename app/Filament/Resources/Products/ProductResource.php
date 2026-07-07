<?php

namespace App\Filament\Resources\Products;

use App\Enums\ProductStatus;
use App\Enums\StockStatus;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\VehicleGeneration;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\FileUpload;
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
                    Tab::make('Изображения')
                        ->schema([
                            Repeater::make('images')
                                ->label('Медиатека товара')
                                ->relationship()
                                ->orderColumn('position')
                                ->schema([
                                    FileUpload::make('path')
                                        ->label('Изображение')
                                        ->disk('public')
                                        ->directory('uploads/products/manual')
                                        ->image()
                                        ->imageEditor()
                                        ->imagePreviewHeight('160')
                                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                        ->maxSize((int) ceil(config('media.max_source_size', 15 * 1024 * 1024) / 1024))
                                        ->required()
                                        ->columnSpanFull(),
                                    TextInput::make('alt')
                                        ->label('Alt')
                                        ->maxLength(255),
                                    Select::make('source_type')
                                        ->label('Источник')
                                        ->options([
                                            'manual' => 'Ручная загрузка',
                                            'import' => 'Импорт',
                                            'default' => 'Дефолтное',
                                        ])
                                        ->default('manual')
                                        ->required(),
                                    TextInput::make('position')
                                        ->label('Позиция')
                                        ->numeric()
                                        ->default(0)
                                        ->required(),
                                    Toggle::make('is_default')
                                        ->label('Дефолтное')
                                        ->default(false),
                                    Toggle::make('is_main')
                                        ->label('Главное')
                                        ->helperText('После сохранения у товара останется только одно главное изображение. Главное изображение всегда видимое.')
                                        ->default(false),
                                    Toggle::make('is_visible')
                                        ->label('Показывать')
                                        ->default(true),
                                ])
                                ->reorderable()
                                ->columns(3)
                                ->addActionLabel('Добавить изображение')
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
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
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
            ->with(['category.parent', 'mainImage'])
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
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
