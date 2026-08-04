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
use App\Models\ProductFitment;
use App\Models\ProductImage;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Catalog\CatalogRelationIdNormalizer;
use App\Services\Catalog\ProductAdminService;
use App\Services\Catalog\ProductOptionTemplateResolver;
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
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    public static function requiresCompactPrice(mixed $managementMode, mixed $variants): bool
    {
        return $managementMode === ProductVariant::MANAGEMENT_TECHNICAL
            || empty($variants);
    }

    /** @return array<int|string, string> */
    public static function productCategoryOptions(mixed $selectedId = null): array
    {
        $selected = self::safeOptionId($selectedId);

        if (! $selected['valid']) {
            return [];
        }

        $selectedId = $selected['id'];

        return ProductCategory::withTrashed()
            ->where(function ($query) use ($selectedId): void {
                $query
                    ->where(function ($active): void {
                        $active->where('is_active', true)->whereNull('deleted_at');
                    })
                    ->when($selectedId !== null, fn ($options) => $options->orWhere('id', $selectedId));
            })
            ->with('parent.parent')
            ->orderBy('full_slug')
            ->get()
            ->mapWithKeys(fn (ProductCategory $category): array => [
                $category->getKey() => self::vehicleLabel(
                    $category->full_title.' · '.$category->full_slug,
                    $category->is_active,
                    $category->trashed(),
                ),
            ])
            ->all();
    }

    /** @return array<int|string, string> */
    public static function partTypeOptions(mixed $selectedId = null): array
    {
        $selected = self::safeOptionId($selectedId);

        if (! $selected['valid']) {
            return [];
        }

        $selectedId = $selected['id'];

        return PartType::withTrashed()
            ->where(function ($query) use ($selectedId): void {
                $query
                    ->where(function ($active): void {
                        $active->where('is_active', true)->whereNull('deleted_at');
                    })
                    ->when($selectedId !== null, fn ($options) => $options->orWhere('id', $selectedId));
            })
            ->orderBy('full_slug')
            ->get()
            ->mapWithKeys(fn (PartType $partType): array => [
                $partType->getKey() => self::partTypeLabel($partType),
            ])
            ->all();
    }

    /** @return array<int|string, string> */
    public static function vehicleMakeOptions(mixed $selectedId = null): array
    {
        $selected = self::safeOptionId($selectedId);

        if (! $selected['valid']) {
            return [];
        }

        $selectedId = $selected['id'];

        return VehicleMake::withTrashed()
            ->where(function ($query) use ($selectedId): void {
                $query
                    ->where(function ($active): void {
                        $active->where('is_active', true)->whereNull('deleted_at');
                    })
                    ->when($selectedId !== null, fn ($options) => $options->orWhere('id', $selectedId));
            })
            ->orderBy('position')
            ->orderBy('title')
            ->get()
            ->mapWithKeys(fn (VehicleMake $make): array => [
                $make->getKey() => self::vehicleLabel($make->title, $make->is_active, $make->trashed()),
            ])
            ->all();
    }

    /** @return array<int|string, string> */
    public static function vehicleModelOptions(mixed $makeId, mixed $selectedId = null): array
    {
        $make = self::safeOptionId($makeId);
        $selected = self::safeOptionId($selectedId);

        if (! $make['valid'] || $make['id'] === null || ! $selected['valid']) {
            return [];
        }

        $makeId = $make['id'];
        $selectedId = $selected['id'];

        return VehicleModel::withTrashed()
            ->where('vehicle_make_id', $makeId)
            ->where(function ($query) use ($selectedId): void {
                $query
                    ->where(function ($active): void {
                        $active->where('is_active', true)->whereNull('deleted_at');
                    })
                    ->when($selectedId !== null, fn ($options) => $options->orWhere('id', $selectedId));
            })
            ->orderBy('position')
            ->orderBy('title')
            ->get()
            ->mapWithKeys(fn (VehicleModel $model): array => [
                $model->getKey() => self::vehicleLabel($model->title, $model->is_active, $model->trashed()),
            ])
            ->all();
    }

    /** @return array<int|string, string> */
    public static function vehicleGenerationOptions(mixed $modelId, mixed $selectedId = null): array
    {
        $model = self::safeOptionId($modelId);
        $selected = self::safeOptionId($selectedId);

        if (! $model['valid'] || $model['id'] === null || ! $selected['valid']) {
            return [];
        }

        $modelId = $model['id'];
        $selectedId = $selected['id'];

        return VehicleGeneration::withTrashed()
            ->where('vehicle_model_id', $modelId)
            ->where(function ($query) use ($selectedId): void {
                $query
                    ->where(function ($active): void {
                        $active->where('is_active', true)->whereNull('deleted_at');
                    })
                    ->when($selectedId !== null, fn ($options) => $options->orWhere('id', $selectedId));
            })
            ->orderBy('position')
            ->orderBy('title')
            ->get()
            ->mapWithKeys(fn (VehicleGeneration $generation): array => [
                $generation->getKey() => self::vehicleLabel(
                    trim($generation->title.' '.($generation->years_label ?: '')),
                    $generation->is_active,
                    $generation->trashed(),
                ),
            ])
            ->all();
    }

    /** @return array<int|string, string> */
    public static function optionTemplateOptions(
        ProductType|string|null $productType,
        mixed $partTypeId = null,
        mixed $selectedId = null,
    ): array {
        $scope = $productType instanceof ProductType ? $productType->value : (string) $productType;
        $partType = self::safeOptionId($partTypeId);
        $selected = self::safeOptionId($selectedId);

        if (! $partType['valid'] || ! $selected['valid']) {
            return [];
        }

        $partTypeId = $partType['id'];
        $selectedId = $selected['id'];

        return ProductOptionTemplate::query()
            ->where(function ($query) use ($partTypeId, $scope, $selectedId): void {
                $query->where(function ($compatible) use ($partTypeId, $scope): void {
                    $compatible
                        ->where('is_active', true)
                        ->whereIn('applies_to', array_filter([
                            ProductOptionGroup::APPLIES_ALL,
                            $scope,
                        ]))
                        ->where(function ($partType) use ($partTypeId): void {
                            $partType->whereNull('part_type_id')
                                ->when($partTypeId !== null, fn ($options) => $options->orWhere('part_type_id', $partTypeId));
                        });
                })->when($selectedId !== null, fn ($options) => $options->orWhere('id', $selectedId));
            })
            ->orderBy('position')
            ->orderBy('title')
            ->get()
            ->mapWithKeys(fn (ProductOptionTemplate $template): array => [
                $template->getKey() => self::optionActiveLabel($template->title, $template->is_active),
            ])
            ->all();
    }

    /** @return array{valid: bool, id: ?int} */
    private static function safeOptionId(mixed $value): array
    {
        try {
            return [
                'valid' => true,
                'id' => app(CatalogRelationIdNormalizer::class)->nullablePositive($value, 'id'),
            ];
        } catch (ValidationException) {
            return ['valid' => false, 'id' => null];
        }
    }

    /** @return array<int|string, string> */
    public static function optionGroupOptions(mixed $templateId = null, mixed $selectedId = null): array
    {
        return ProductOptionGroup::query()
            ->where(function ($query) use ($selectedId, $templateId): void {
                if (filled($selectedId)) {
                    $query->whereKey($selectedId)->orWhere(function ($available) use ($templateId): void {
                        $available->where('is_active', true)
                            ->when(filled($templateId), fn ($groups) => $groups->whereHas(
                                'templateItems',
                                fn ($items) => $items->where('product_option_template_id', $templateId),
                            ));
                    });

                    return;
                }

                $query->where('is_active', true)
                    ->when(filled($templateId), fn ($groups) => $groups->whereHas(
                        'templateItems',
                        fn ($items) => $items->where('product_option_template_id', $templateId),
                    ));
            })
            ->orderBy('position')
            ->orderBy('title')
            ->get()
            ->mapWithKeys(fn (ProductOptionGroup $group): array => [
                $group->getKey() => self::optionActiveLabel($group->title, $group->is_active),
            ])
            ->all();
    }

    /** @return array<int|string, string> */
    public static function optionValueOptions(mixed $groupId, mixed $templateId = null, mixed $selectedId = null): array
    {
        if (blank($groupId)) {
            return [];
        }

        return ProductOptionValue::query()
            ->where('product_option_group_id', $groupId)
            ->where(function ($query) use ($selectedId, $templateId): void {
                if (filled($selectedId)) {
                    $query->whereKey($selectedId)->orWhere(function ($available) use ($templateId): void {
                        $available->where('is_active', true)
                            ->when(filled($templateId), fn ($values) => $values->whereHas(
                                'templateItems',
                                fn ($items) => $items->where('product_option_template_id', $templateId),
                            ));
                    });

                    return;
                }

                $query->where('is_active', true)
                    ->when(filled($templateId), fn ($values) => $values->whereHas(
                        'templateItems',
                        fn ($items) => $items->where('product_option_template_id', $templateId),
                    ));
            })
            ->orderBy('position')
            ->orderBy('title')
            ->get()
            ->mapWithKeys(fn (ProductOptionValue $value): array => [
                $value->getKey() => self::optionActiveLabel($value->title, $value->is_active),
            ])
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
                    ->afterStateUpdated(function (ProductType|string|null $state, Get $get, Set $set): void {
                        if (! self::isAutoPartState($state)) {
                            $set('product_option_template_id', null);

                            return;
                        }

                        self::assignDefaultOptionTemplate(
                            $state,
                            $get('part_type_id'),
                            $get('product_option_template_id'),
                            $set,
                        );
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
                    ->options(fn (Get $get): array => self::productCategoryOptions($get('product_category_id')))
                    ->nullable()
                    ->helperText('Категория витрины. Тип конкретной детали выбирается отдельно.'),
                Select::make('part_type_id')
                    ->label('Тип детали')
                    ->searchable()
                    ->preload()
                    ->options(fn (Get $get): array => self::partTypeOptions($get('part_type_id')))
                    ->live()
                    ->hidden(fn (Get $get): bool => ! self::isAutoPartState($get('product_type')))
                    ->dehydrated(fn (Get $get): bool => self::isAutoPartState($get('product_type')))
                    ->required(fn (Get $get): bool => self::isAutoPartState($get('product_type')))
                    ->afterStateUpdated(fn (mixed $state, Get $get, Set $set) => self::assignDefaultOptionTemplate(
                        $get('product_type'),
                        $state,
                        $get('product_option_template_id'),
                        $set,
                    )),
                Select::make('product_option_template_id')
                    ->label('Шаблон опций')
                    ->searchable()
                    ->preload()
                    ->options(fn (Get $get): array => self::optionTemplateOptions(
                        $get('product_type'),
                        $get('part_type_id'),
                        $get('product_option_template_id'),
                    ))
                    ->hidden(fn (Get $get): bool => ! self::isAutoPartState($get('product_type')))
                    ->dehydrated(fn (Get $get): bool => self::isAutoPartState($get('product_type')))
                    ->live()
                    ->nullable()
                    ->helperText('Изменение шаблона не изменяет уже созданные варианты товара. Генерация выполняется отдельным действием.'),
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

    private static function assignDefaultOptionTemplate(
        ProductType|string|null $productType,
        mixed $partTypeId,
        mixed $templateId,
        Set $set,
    ): void {
        if (! self::isAutoPartState($productType) || blank($partTypeId) || filled($templateId)) {
            return;
        }

        $defaultTemplateId = app(ProductOptionTemplateResolver::class)
            ->resolveDefaultForAutoPart((int) $partTypeId)
            ?->getKey();

        if (filled($defaultTemplateId)) {
            $set('product_option_template_id', $defaultTemplateId);
        }
    }

    private static function priceAndStockTab(): Tab
    {
        return Tab::make('Цена и наличие')
            ->key('product-price-stock-tab', isInheritable: false)
            ->schema([
                Section::make('Основная цена и наличие')
                    ->description('Эти поля используются для компактного отображения товара и базового предложения.')
                    ->schema([
                        Hidden::make('variant_management_mode')
                            ->dehydrated(false),
                        TextInput::make('sku')
                            ->label('SKU товара')
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('price')
                            ->label('Цена')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('₽')
                            ->required(fn (Get $get): bool => self::requiresCompactPrice(
                                $get('variant_management_mode'),
                                $get('variants'),
                            )),
                        TextInput::make('old_price')
                            ->label('Старая цена')
                            ->numeric()
                            ->minValue(0)
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
                            ->mutateRelationshipDataBeforeFillUsing(function (array $data): array {
                                $data['options'] = ProductVariant::optionsWithoutManagementMetadata($data['options'] ?? null);

                                return $data;
                            })
                            ->schema([
                                TextInput::make('sku')
                                    ->label('SKU варианта')
                                    ->maxLength(255)
                                    ->distinct()
                                    ->unique(ignoreRecord: true),
                                TextInput::make('title')
                                    ->label('Название варианта')
                                    ->maxLength(255),
                                TextInput::make('price')
                                    ->label('Цена')
                                    ->numeric()
                                    ->minValue(0)
                                    ->prefix('₽')
                                    ->required(),
                                TextInput::make('old_price')
                                    ->label('Старая цена')
                                    ->numeric()
                                    ->minValue(0)
                                    ->prefix('₽'),
                                TextInput::make('stock_quantity')
                                    ->label('Остаток')
                                    ->numeric()
                                    ->minValue(0),
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
                                                $get('product_option_group_id'),
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
                                                $get('product_option_value_id'),
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
            ->reorderable(false)
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
                            ->saveRelationshipsUsing(function (Repeater $component): void {
                                $product = $component->getRecord();

                                if (! $product instanceof Product) {
                                    return;
                                }

                                $fitments = collect($component->getItems())
                                    ->map(fn ($item): array => $item->getState(shouldCallHooksBefore: false))
                                    ->values()
                                    ->all();

                                app(ProductAdminService::class)->syncFitments($product, $fitments);
                                $product->unsetRelation('fitments');
                            })
                            ->hidden(fn (Get $get): bool => ! self::isAutoPartState($get('product_type')))
                            ->saveRelationshipsWhenHidden(false)
                            ->defaultItems(0)
                            ->schema([
                                Select::make('vehicle_make_id')
                                    ->label('Марка')
                                    ->searchable()
                                    ->preload()
                                    ->options(fn (Get $get): array => self::vehicleMakeOptions($get('vehicle_make_id')))
                                    ->live()
                                    ->afterStateUpdated(function (Set $set): void {
                                        $set('vehicle_model_id', null);
                                        $set('vehicle_generation_id', null);
                                    })
                                    ->required()
                                    ->dehydrated(false),
                                Select::make('vehicle_model_id')
                                    ->label('Модель')
                                    ->searchable()
                                    ->preload()
                                    ->options(fn (Get $get): array => self::vehicleModelOptions(
                                        $get('vehicle_make_id'),
                                        $get('vehicle_model_id'),
                                    ))
                                    ->live()
                                    ->afterStateUpdated(fn (Set $set): mixed => $set('vehicle_generation_id', null))
                                    ->required()
                                    ->disabled(fn (Get $get): bool => blank($get('vehicle_make_id')))
                                    ->rule(function (Get $get): mixed {
                                        $make = self::safeOptionId($get('vehicle_make_id'));

                                        return Rule::exists('vehicle_models', 'id')
                                            ->where('vehicle_make_id', $make['valid'] ? ($make['id'] ?? 0) : 0);
                                    })
                                    ->dehydrated(false),
                                Select::make('vehicle_generation_id')
                                    ->label('Поколение')
                                    ->searchable()
                                    ->preload()
                                    ->options(fn (Get $get): array => self::vehicleGenerationOptions(
                                        $get('vehicle_model_id'),
                                        $get('vehicle_generation_id'),
                                    ))
                                    ->afterStateHydrated(function (mixed $state, Set $set, ?ProductFitment $record): void {
                                        $generation = $record?->generation;

                                        if (! $generation instanceof VehicleGeneration && filled($state)) {
                                            $generation = VehicleGeneration::withTrashed()->find($state);
                                        }

                                        if (! $generation instanceof VehicleGeneration) {
                                            return;
                                        }

                                        $model = $generation->model
                                            ?? VehicleModel::withTrashed()->find($generation->vehicle_model_id);

                                        $set('vehicle_model_id', $model?->getKey());
                                        $set('vehicle_make_id', $model?->vehicle_make_id);
                                    })
                                    ->required()
                                    ->disabled(fn (Get $get): bool => blank($get('vehicle_model_id')))
                                    ->rule(function (Get $get): mixed {
                                        $model = self::safeOptionId($get('vehicle_model_id'));

                                        return Rule::exists('vehicle_generations', 'id')
                                            ->where('vehicle_model_id', $model['valid'] ? ($model['id'] ?? 0) : 0);
                                    })
                                    ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                                TextInput::make('note')
                                    ->label('Примечание')
                                    ->maxLength(255),
                                Toggle::make('is_primary')
                                    ->label('Основной автомобиль')
                                    ->default(false),
                            ])
                            ->columns(5)
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

    public static function partTypeLabel(PartType $partType): string
    {
        return self::vehicleLabel($partType->full_title, $partType->is_active, $partType->trashed());
    }

    private static function vehicleLabel(string $title, bool $isActive, bool $isDeleted): string
    {
        return match (true) {
            $isDeleted => $title.' (удалено)',
            ! $isActive => $title.' (неактивно)',
            default => $title,
        };
    }

    private static function optionActiveLabel(string $title, bool $isActive): string
    {
        return $isActive ? $title : $title.' (Неактивно)';
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
