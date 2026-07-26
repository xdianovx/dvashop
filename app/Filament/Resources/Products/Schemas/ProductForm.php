<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Enums\StockStatus;
use App\Filament\Resources\Products\Actions\ProductGalleryActions;
use App\Filament\Schemas\SeoSchema;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductCharacteristic;
use App\Models\ProductImage;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use App\Models\ProductOptionValue;
use App\Models\VehicleGeneration;
use App\Services\Media\MediaUrlService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

final class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Product tabs')
                ->id('product-tabs')
                ->persistTabInQueryString('product-tab')
                ->tabs([
                    self::mainTab(),
                    self::priceAndStockTab(),
                    self::characteristicsTab(),
                    self::galleryTab(),
                    self::fitmentsTab(),
                    self::seoTab(),
                    self::technicalTab(),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function isAutoPartState(ProductType|string|null $state): bool
    {
        return ($state instanceof ProductType ? $state->value : $state) === ProductType::AutoPart->value;
    }

    /** @return array<int|string, string> */
    public static function productCategoryOptions(): array
    {
        return ProductCategory::query()
            ->with('parent.parent')
            ->orderBy('full_slug')
            ->get()
            ->mapWithKeys(fn (ProductCategory $category): array => [
                $category->getKey() => $category->full_title.' · '.$category->full_slug,
            ])
            ->all();
    }

    /** @return array<int|string, string> */
    public static function partTypeOptions(): array
    {
        return PartType::query()
            ->orderBy('full_slug')
            ->pluck('full_title', 'id')
            ->all();
    }

    /** @return array<int|string, string> */
    public static function vehicleGenerationOptions(): array
    {
        return VehicleGeneration::query()
            ->with('model.make')
            ->get()
            ->sortBy('display_title')
            ->mapWithKeys(fn (VehicleGeneration $generation): array => [
                $generation->getKey() => $generation->display_title,
            ])
            ->all();
    }

    /** @return array<int|string, string> */
    public static function optionTemplateOptions(ProductType|string|null $productType): array
    {
        $scope = $productType instanceof ProductType ? $productType->value : (string) $productType;

        return ProductOptionTemplate::query()
            ->where('is_active', true)
            ->whereIn('applies_to', array_filter([ProductOptionGroup::APPLIES_ALL, $scope]))
            ->orderBy('position')
            ->orderBy('title')
            ->pluck('title', 'id')
            ->all();
    }

    /** @return array<int|string, string> */
    public static function optionGroupOptions(mixed $templateId = null): array
    {
        return ProductOptionGroup::query()
            ->where('is_active', true)
            ->when(filled($templateId), fn ($query) => $query->whereHas(
                'templateItems',
                fn ($items) => $items->where('product_option_template_id', $templateId),
            ))
            ->orderBy('position')
            ->orderBy('title')
            ->pluck('title', 'id')
            ->all();
    }

    /** @return array<int|string, string> */
    public static function optionValueOptions(mixed $groupId, mixed $templateId = null): array
    {
        if (blank($groupId)) {
            return [];
        }

        return ProductOptionValue::query()
            ->where('product_option_group_id', $groupId)
            ->where('is_active', true)
            ->when(filled($templateId), fn ($query) => $query->whereHas(
                'templateItems',
                fn ($items) => $items->where('product_option_template_id', $templateId),
            ))
            ->orderBy('position')
            ->orderBy('title')
            ->pluck('title', 'id')
            ->all();
    }

    private static function mainTab(): Tab
    {
        return Tab::make('Основное')
            ->key('product-main-tab', isInheritable: false)
            ->schema([
                Select::make('product_type')
                    ->label('Тип товара')
                    ->options([
                        ProductType::AutoPart->value => ProductType::AutoPart->label(),
                        ProductType::Generic->value => ProductType::Generic->label(),
                    ])
                    ->default(ProductType::AutoPart->value)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (ProductType|string|null $state, Set $set): void {
                        if (! self::isAutoPartState($state)) {
                            $set('part_type_id', null);
                            $set('product_option_template_id', null);
                            $set('fitments', []);
                        }
                    }),
                TextInput::make('title')
                    ->label('Название')
                    ->required()
                    ->maxLength(255),
                TextInput::make('slug')
                    ->label('Slug')
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->helperText('Можно оставить пустым — будет создан из названия.'),
                Select::make('product_category_id')
                    ->label('Категория магазина')
                    ->searchable()
                    ->preload()
                    ->options(self::productCategoryOptions(...))
                    ->nullable()
                    ->helperText('Категория витрины. Тип конкретной детали выбирается отдельно.'),
                Select::make('part_type_id')
                    ->label('Тип детали')
                    ->searchable()
                    ->preload()
                    ->options(self::partTypeOptions(...))
                    ->hidden(fn (Get $get): bool => ! self::isAutoPartState($get('product_type')))
                    ->dehydrated(fn (Get $get): bool => self::isAutoPartState($get('product_type')))
                    ->required(fn (Get $get): bool => self::isAutoPartState($get('product_type'))),
                Select::make('product_option_template_id')
                    ->label('Шаблон опций')
                    ->searchable()
                    ->preload()
                    ->options(fn (Get $get): array => self::optionTemplateOptions($get('product_type')))
                    ->live()
                    ->default(fn (): mixed => ProductOptionTemplate::query()
                        ->where('slug', 'default_auto_part')
                        ->where('is_active', true)
                        ->value('id'))
                    ->nullable()
                    ->helperText('Шаблон определяет доступные группы и значения опций для вариантов товара.'),
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
                    ->rows(7)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    private static function priceAndStockTab(): Tab
    {
        return Tab::make('Цена и наличие')
            ->key('product-price-stock-tab', isInheritable: false)
            ->schema([
                Section::make('Основная цена и наличие')
                    ->description('Эти поля используются для компактного отображения товара и базового предложения.')
                    ->schema([
                        TextInput::make('sku')
                            ->label('SKU товара')
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('price')
                            ->label('Цена')
                            ->numeric()
                            ->prefix('₽')
                            ->required(fn (Get $get): bool => empty($get('variants'))),
                        TextInput::make('old_price')
                            ->label('Старая цена')
                            ->numeric()
                            ->prefix('₽'),
                        TextInput::make('default_stock_quantity')
                            ->label('Остаток')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Остаток основного варианта.'),
                        Select::make('stock_status')
                            ->label('Наличие')
                            ->options(StockStatus::options())
                            ->default(StockStatus::InStock->value)
                            ->required(),
                    ])
                    ->columns(5),
                Section::make('Варианты товара')
                    ->description('Откройте секцию только когда у товара есть несколько комплектаций или вариантов.')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Repeater::make('variants')
                            ->label('')
                            ->relationship()
                            ->schema([
                                TextInput::make('sku')
                                    ->label('SKU варианта')
                                    ->maxLength(255)
                                    ->unique(ignoreRecord: true),
                                TextInput::make('title')
                                    ->label('Название варианта')
                                    ->maxLength(255),
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
                                    ->label('Основной вариант')
                                    ->default(false)
                                    ->fixIndistinctState(),
                                Toggle::make('is_active')
                                    ->label('Активен')
                                    ->default(true),
                                Repeater::make('variantOptionValues')
                                    ->label('Выбранные опции')
                                    ->relationship()
                                    ->schema([
                                        Select::make('product_option_group_id')
                                            ->label('Группа')
                                            ->options(fn (Get $get): array => self::optionGroupOptions(
                                                $get('data.product_option_template_id', isAbsolute: true),
                                            ))
                                            ->searchable()
                                            ->preload()
                                            ->live()
                                            ->required()
                                            ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                            ->afterStateUpdated(fn (Set $set): mixed => $set('product_option_value_id', null)),
                                        Select::make('product_option_value_id')
                                            ->label('Значение')
                                            ->options(fn (Get $get): array => self::optionValueOptions(
                                                $get('product_option_group_id'),
                                                $get('data.product_option_template_id', isAbsolute: true),
                                            ))
                                            ->searchable()
                                            ->preload()
                                            ->required()
                                            ->disabled(fn (Get $get): bool => blank($get('product_option_group_id'))),
                                    ])
                                    ->defaultItems(0)
                                    ->columns(2)
                                    ->reorderable(false)
                                    ->addActionLabel('Добавить опцию')
                                    ->helperText('Для каждой группы можно выбрать только одно значение.')
                                    ->columnSpanFull(),
                                Section::make('Совместимость со старыми данными')
                                    ->description('JSON используется только как резервный snapshot, если нормализованные опции не выбраны.')
                                    ->collapsible()
                                    ->collapsed()
                                    ->schema([
                                        KeyValue::make('options')
                                            ->label('Резервные опции JSON')
                                            ->keyLabel('Код опции')
                                            ->valueLabel('Значение'),
                                    ])
                                    ->columnSpanFull(),
                            ])
                            ->defaultItems(0)
                            ->columns(4)
                            ->collapsible()
                            ->collapsed()
                            ->itemLabel(fn (array $state): string => self::variantItemLabel($state))
                            ->addActionLabel('Добавить вариант')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function characteristicsTab(): Tab
    {
        return Tab::make('Характеристики')
            ->key('product-characteristics-tab', isInheritable: false)
            ->schema([
                Section::make('Характеристики товара')
                    ->description('Пары «название — значение» для будущего блока характеристик на карточке товара.')
                    ->schema([
                        Repeater::make('characteristics')
                            ->label('')
                            ->relationship()
                            ->schema([
                                TextInput::make('name')
                                    ->label('Название')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('value')
                                    ->label('Значение')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('unit')
                                    ->label('Ед. изм.')
                                    ->maxLength(50),
                                Select::make('source_type')
                                    ->label('Источник')
                                    ->options([
                                        ProductCharacteristic::SOURCE_MANUAL => 'Ручное',
                                        ProductCharacteristic::SOURCE_DEFAULT => 'По умолчанию',
                                        ProductCharacteristic::SOURCE_IMPORT => 'Импорт',
                                    ])
                                    ->default(ProductCharacteristic::SOURCE_MANUAL)
                                    ->disabled()
                                    ->dehydrated(),
                                Toggle::make('is_visible')
                                    ->label('Показывать на сайте')
                                    ->default(true),
                                TextInput::make('position')
                                    ->label('Порядок')
                                    ->numeric()
                                    ->default(0)
                                    ->required(),
                            ])
                            ->defaultItems(0)
                            ->columns(3)
                            ->orderColumn('position')
                            ->collapsible()
                            ->itemLabel(fn (array $state): string => implode(' · ', array_filter([
                                $state['name'] ?? null,
                                $state['value'] ?? null,
                                $state['unit'] ?? null,
                            ])) ?: 'Характеристика')
                            ->addActionLabel('Добавить характеристику')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function galleryTab(): Tab
    {
        return Tab::make('Галерея')
            ->key('product-gallery-tab', isInheritable: false)
            ->schema([
                View::make('filament.resources.products.gallery-main-preview')
                    ->viewData(fn (?Product $record): array => ['product' => $record])
                    ->visible(fn (?Product $record): bool => $record instanceof Product && $record->exists),
                FileUpload::make('gallery_uploads')
                    ->label('Добавить изображения')
                    ->disk('public')
                    ->directory('uploads/products/pending/manual')
                    ->visibility('public')
                    ->image()
                    ->imageEditor()
                    ->multiple()
                    ->reorderable()
                    ->openable()
                    ->downloadable()
                    ->acceptedFileTypes(config('media.allowed_mimes', ['image/jpeg', 'image/png', 'image/webp']))
                    ->maxSize((int) ceil(config('media.max_source_size', 15 * 1024 * 1024) / 1024))
                    ->helperText('Можно выбрать несколько JPG, JPEG, PNG или WebP. После сохранения каждый файл будет обработан и преобразован через общий media pipeline.')
                    ->columnSpanFull(),
                Section::make('Действия с дефолтным изображением')
                    ->description('Первая операция сохраняет остальные изображения. Сброс удаляет ручные и импортные изображения только после подтверждения.')
                    ->afterHeader([
                        ProductGalleryActions::makeDefaultMain('form_make_default_main'),
                        ProductGalleryActions::resetToDefault('form_reset_gallery_to_default'),
                    ])
                    ->schema([])
                    ->visible(fn (?Product $record): bool => $record instanceof Product && $record->exists),
                self::galleryImagesRepeater(),
            ]);
    }

    private static function galleryImagesRepeater(): Repeater
    {
        return Repeater::make('images')
            ->label('Изображения товара')
            ->relationship()
            ->defaultItems(0)
            ->mutateRelationshipDataBeforeFillUsing(function (array $data): array {
                $image = new ProductImage;
                $image->forceFill($data);
                $data['file_url'] = app(MediaUrlService::class)->productImageFileUrl($image);

                return $data;
            })
            ->schema([
                Hidden::make('file_url')
                    ->dehydrated(false),
                View::make('filament.resources.products.gallery-image-preview')
                    ->viewData(fn (Get $get): array => [
                        'url' => (string) $get('file_url'),
                        'alt' => (string) ($get('alt') ?: 'Изображение товара'),
                        'sourceLabel' => ProductImage::sourceTypeLabel($get('source_type')),
                        'isMain' => (bool) $get('is_main'),
                        'isVisible' => (bool) $get('is_visible'),
                    ]),
                Select::make('source_type')
                    ->label('Источник')
                    ->options([
                        ProductImage::SOURCE_DEFAULT => 'Дефолтное',
                        ProductImage::SOURCE_IMPORT => 'Импорт',
                        ProductImage::SOURCE_MANUAL => 'Ручное',
                    ])
                    ->disabled()
                    ->dehydrated(false),
                Toggle::make('is_visible')
                    ->label('Показывать')
                    ->disabled(fn (Get $get): bool => (bool) $get('is_main'))
                    ->helperText('Главное изображение всегда видимое.'),
                Toggle::make('is_main')
                    ->label('Главное')
                    ->live()
                    ->fixIndistinctState()
                    ->afterStateUpdated(function (bool $state, Set $set): void {
                        if ($state) {
                            $set('is_visible', true);
                        }
                    }),
                TextInput::make('position')
                    ->label('Порядок')
                    ->numeric()
                    ->required(),
                TextInput::make('alt')
                    ->label('Alt')
                    ->maxLength(255)
                    ->columnSpan(2),
            ])
            ->columns(4)
            ->orderColumn('position')
            ->addable(false)
            ->collapsible()
            ->itemLabel(fn (array $state): string => implode(' · ', array_filter([
                ProductImage::sourceTypeLabel($state['source_type'] ?? null),
                ! empty($state['is_main']) ? 'Главное' : null,
                $state['alt'] ?? null,
            ])))
            ->deleteAction(fn (Action $action): Action => $action
                ->requiresConfirmation()
                ->modalHeading('Удалить изображение из галереи?')
                ->modalDescription('Для ручного и импортного изображения будут удалены файл и его конверсии. Для дефолтного удалится только связь с товаром.'))
            ->extraItemActions([
                Action::make('open_gallery_image')
                    ->label('Открыть')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (array $arguments, Repeater $component): ?string => self::galleryItemUrl($arguments, $component))
                    ->openUrlInNewTab(),
                Action::make('download_gallery_image')
                    ->label('Скачать')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (array $arguments, Repeater $component): ?string => self::galleryItemUrl($arguments, $component))
                    ->extraAttributes(['download' => true]),
            ])
            ->visible(fn (?Product $record): bool => $record instanceof Product && $record->exists)
            ->columnSpanFull();
    }

    private static function fitmentsTab(): Tab
    {
        return Tab::make('Подходит к авто')
            ->key('product-fitments-tab', isInheritable: false)
            ->hidden(fn (Get $get): bool => ! self::isAutoPartState($get('product_type')))
            ->schema([
                Section::make('Автомобили')
                    ->description('Здесь выбираются автомобили, к которым подходит эта деталь.')
                    ->schema([
                        Repeater::make('fitments')
                            ->label('Поколения авто')
                            ->relationship()
                            ->hidden(fn (Get $get): bool => ! self::isAutoPartState($get('product_type')))
                            ->saveRelationshipsWhenHidden(false)
                            ->defaultItems(0)
                            ->schema([
                                Select::make('vehicle_generation_id')
                                    ->label('Поколение')
                                    ->searchable()
                                    ->preload()
                                    ->options(self::vehicleGenerationOptions(...))
                                    ->required(),
                                TextInput::make('note')
                                    ->label('Примечание')
                                    ->maxLength(255),
                                Toggle::make('is_primary')
                                    ->label('Основной автомобиль')
                                    ->default(false)
                                    ->fixIndistinctState(),
                            ])
                            ->columns(3)
                            ->addActionLabel('Добавить автомобиль')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function seoTab(): Tab
    {
        return Tab::make('SEO')
            ->key('product-seo-tab', isInheritable: false)
            ->schema(SeoSchema::fields())
            ->columns(2);
    }

    private static function technicalTab(): Tab
    {
        return Tab::make('Техническая информация')
            ->key('product-technical-tab', isInheritable: false)
            ->schema([
                Section::make('Служебные данные')
                    ->description('Поля заполняются системой и доступны только для диагностики.')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        TextInput::make('import_key')
                            ->label('Import key')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('import_source')
                            ->label('Import source')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('last_import_run_id')
                            ->label('Last import run ID')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('created_at')
                            ->label('Создано')
                            ->formatStateUsing(fn ($state): string => filled($state) ? Carbon::parse($state)->format('d.m.Y H:i:s') : '—')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('updated_at')
                            ->label('Обновлено')
                            ->formatStateUsing(fn ($state): string => filled($state) ? Carbon::parse($state)->format('d.m.Y H:i:s') : '—')
                            ->disabled()
                            ->dehydrated(false),
                    ])
                    ->columns(2),
            ]);
    }

    /** @param array<string, mixed> $state */
    private static function variantItemLabel(array $state): string
    {
        $valueIds = collect($state['variantOptionValues'] ?? [])
            ->pluck('product_option_value_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->values();

        if ($valueIds->isNotEmpty()) {
            $summary = ProductOptionValue::query()
                ->with('group')
                ->whereKey($valueIds)
                ->get()
                ->sortBy(fn (ProductOptionValue $value): string => sprintf(
                    '%010d:%010d',
                    (int) $value->group?->position,
                    (int) $value->position,
                ))
                ->map(fn (ProductOptionValue $value): string => ($value->group?->title ?? 'Опция').': '.$value->title)
                ->implode('; ');

            if ($summary !== '') {
                return $summary;
            }
        }

        return (string) (($state['title'] ?? null) ?: ($state['sku'] ?? null) ?: 'Вариант товара');
    }

    /** @param array<string, mixed> $arguments */
    private static function galleryItemUrl(array $arguments, Repeater $component): ?string
    {
        $item = $arguments['item'] ?? null;

        if (! is_string($item)) {
            return null;
        }

        $state = $component->getRawItemState($item);
        $url = $state['file_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }
}
