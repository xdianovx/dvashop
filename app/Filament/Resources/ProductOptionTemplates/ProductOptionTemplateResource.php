<?php

namespace App\Filament\Resources\ProductOptionTemplates;

use App\Filament\Resources\ProductOptionGroups\ProductOptionGroupResource;
use App\Filament\Resources\ProductOptionTemplates\Pages\CreateProductOptionTemplate;
use App\Filament\Resources\ProductOptionTemplates\Pages\EditProductOptionTemplate;
use App\Filament\Resources\ProductOptionTemplates\Pages\ListProductOptionTemplates;
use App\Filament\Resources\ProductOptionTemplates\Pages\ViewProductOptionTemplate;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use App\Models\ProductOptionValue;
use App\Models\User;
use App\Services\Catalog\ProductOptionAdminService;
use App\Services\Catalog\ProductOptionCombinationCalculator;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductOptionTemplateResource extends Resource
{
    protected static ?string $model = ProductOptionTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-plus';

    protected static ?int $navigationSort = 46;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationGroup(): ?string
    {
        return 'Каталог';
    }

    public static function getNavigationLabel(): string
    {
        return 'Шаблоны опций';
    }

    public static function getModelLabel(): string
    {
        return 'шаблон опций';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Шаблоны опций';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Шаблон')
                ->description('Изменение шаблона не изменяет уже созданные варианты товара. Генерация остаётся отдельным действием товара.')
                ->schema([
                    TextInput::make('title')->label('Название')->required()->maxLength(255),
                    TextInput::make('slug')
                        ->label('Slug')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),
                    Select::make('applies_to')
                        ->label('Область применения')
                        ->options(ProductOptionGroupResource::appliesToOptions())
                        ->default(ProductOptionGroup::APPLIES_ALL)
                        ->live()
                        ->afterStateUpdated(function (mixed $state, Set $set): void {
                            if ($state !== ProductOptionGroup::APPLIES_AUTO_PART) {
                                $set('part_type_id', null);
                            }
                        })
                        ->required(),
                    Select::make('part_type_id')
                        ->label('Тип детали')
                        ->searchable()
                        ->preload()
                        ->options(fn (Get $get): array => ProductForm::partTypeOptions($get('part_type_id')))
                        ->visible(fn (Get $get): bool => $get('applies_to') === ProductOptionGroup::APPLIES_AUTO_PART)
                        ->dehydrated(fn (Get $get): bool => $get('applies_to') === ProductOptionGroup::APPLIES_AUTO_PART)
                        ->nullable(),
                    TextInput::make('position')
                        ->label('Позиция')
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->default(0)
                        ->required(),
                    Toggle::make('is_default')->label('По умолчанию')->default(false),
                    Toggle::make('is_active')->label('Активен')->default(true),
                ])
                ->columns(2),
            Section::make('Разрешённые значения')
                ->description('Количество комбинаций — произведение числа активных значений в каждой активной группе. Лимит: '.ProductOptionCombinationCalculator::MAX_COMBINATIONS.'.')
                ->schema([
                    Repeater::make('template_items')
                        ->label('Группы и значения')
                        ->schema([
                            Select::make('product_option_group_id')
                                ->label('Группа')
                                ->options(fn (Get $get): array => self::groupOptions(
                                    $get('data.applies_to', isAbsolute: true),
                                    $get('product_option_group_id'),
                                ))
                                ->searchable()
                                ->preload()
                                ->live()
                                ->required()
                                ->afterStateUpdated(fn (Set $set): mixed => $set('product_option_value_id', null)),
                            Select::make('product_option_value_id')
                                ->label('Значение')
                                ->options(fn (Get $get): array => self::valueOptions(
                                    $get('product_option_group_id'),
                                    $get('product_option_value_id'),
                                ))
                                ->searchable()
                                ->preload()
                                ->live()
                                ->required()
                                ->disabled(fn (Get $get): bool => blank($get('product_option_group_id'))),
                            TextInput::make('position')
                                ->label('Позиция')
                                ->numeric()
                                ->integer()
                                ->minValue(0)
                                ->default(0)
                                ->required(),
                        ])
                        ->columns(3)
                        ->defaultItems(0)
                        ->reorderable(false)
                        ->collapsible()
                        ->itemLabel(fn (array $state): string => self::itemLabel($state))
                        ->addActionLabel('Добавить разрешённое значение')
                        ->columnSpanFull(),
                    Placeholder::make('preview_groups')
                        ->label('Групп')
                        ->content(fn (Get $get): int => collect($get('template_items') ?? [])
                            ->pluck('product_option_group_id')->filter()->unique()->count()),
                    Placeholder::make('preview_values')
                        ->label('Разрешённых значений')
                        ->content(fn (Get $get): int => collect($get('template_items') ?? [])
                            ->pluck('product_option_value_id')->filter()->unique()->count()),
                    Placeholder::make('preview_combinations')
                        ->label('Возможных комбинаций')
                        ->content(fn (Get $get): int => app(ProductOptionCombinationCalculator::class)
                            ->countForItems(array_values($get('template_items') ?? []))),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->reorderable('position', fn (): bool => auth()->user()?->can('reorder', ProductOptionTemplate::class) ?? false)
            ->authorizeReorder(fn (): bool => auth()->user()?->can('reorder', ProductOptionTemplate::class) ?? false)
            ->columns([
                TextColumn::make('title')->label('Название')->searchable()->sortable(),
                TextColumn::make('slug')->label('Slug')->searchable()->sortable(),
                TextColumn::make('applies_to')
                    ->label('Применение')
                    ->formatStateUsing(fn (string $state): string => ProductOptionGroupResource::appliesToOptions()[$state] ?? $state)
                    ->sortable(),
                TextColumn::make('groups_count')
                    ->label('Групп')
                    ->state(fn (ProductOptionTemplate $record): int => $record->items->pluck('product_option_group_id')->unique()->count()),
                TextColumn::make('items_count')->label('Значений')->numeric()->sortable(),
                TextColumn::make('combination_count')
                    ->label('Комбинаций')
                    ->state(fn (ProductOptionTemplate $record): int => app(ProductOptionCombinationCalculator::class)
                        ->countForTemplate($record)),
                TextColumn::make('products_count')->label('Товаров')->numeric()->sortable(),
                IconColumn::make('is_default')->label('По умолчанию')->boolean(),
                IconColumn::make('is_active')->label('Активен')->boolean(),
                TextColumn::make('position')->label('Позиция')->numeric()->sortable(),
                TextColumn::make('created_at')->label('Создано')->dateTime('d.m.Y H:i')->sortable()->toggleable(),
                TextColumn::make('updated_at')->label('Обновлено')->dateTime('d.m.Y H:i')->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('applies_to')
                    ->label('Область применения')
                    ->options(ProductOptionGroupResource::appliesToOptions()),
                TernaryFilter::make('is_active')
                    ->label('Активность')
                    ->trueLabel('Только активные')
                    ->falseLabel('Только неактивные'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                self::activeAction(),
            ])
            ->emptyStateHeading('Шаблоны опций не найдены');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['items.group', 'items.value'])
            ->withCount(['items', 'products', 'variants']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductOptionTemplates::route('/'),
            'create' => CreateProductOptionTemplate::route('/create'),
            'view' => ViewProductOptionTemplate::route('/{record}'),
            'edit' => EditProductOptionTemplate::route('/{record}/edit'),
        ];
    }

    /** @return array<int|string, string> */
    public static function groupOptions(mixed $appliesTo, mixed $selectedId = null): array
    {
        $scope = (string) $appliesTo;

        return ProductOptionGroup::query()
            ->where(function ($query) use ($selectedId): void {
                $query->where('is_active', true)
                    ->when(filled($selectedId), fn ($options) => $options->orWhere('id', $selectedId));
            })
            ->when($scope !== '', fn ($query) => $query->whereIn('applies_to', [
                ProductOptionGroup::APPLIES_ALL,
                $scope,
            ]))
            ->orderBy('position')
            ->orderBy('title')
            ->get()
            ->mapWithKeys(fn (ProductOptionGroup $group): array => [
                $group->getKey() => self::activeLabel($group->title, $group->is_active),
            ])
            ->all();
    }

    /** @return array<int|string, string> */
    public static function valueOptions(mixed $groupId, mixed $selectedId = null): array
    {
        if (blank($groupId)) {
            return [];
        }

        return ProductOptionValue::query()
            ->where('product_option_group_id', $groupId)
            ->where(function ($query) use ($selectedId): void {
                $query->where('is_active', true)
                    ->when(filled($selectedId), fn ($options) => $options->orWhere('id', $selectedId));
            })
            ->orderBy('position')
            ->orderBy('title')
            ->get()
            ->mapWithKeys(fn (ProductOptionValue $value): array => [
                $value->getKey() => self::activeLabel($value->title, $value->is_active),
            ])
            ->all();
    }

    /** @param array<string, mixed> $state */
    private static function itemLabel(array $state): string
    {
        /** @var array<int|string, string> $groupLabels */
        $groupLabels = once(fn (): array => ProductOptionGroup::query()->pluck('title', 'id')->all());
        /** @var array<int|string, string> $valueLabels */
        $valueLabels = once(fn (): array => ProductOptionValue::query()->pluck('title', 'id')->all());
        $group = $groupLabels[$state['product_option_group_id'] ?? ''] ?? null;
        $value = $valueLabels[$state['product_option_value_id'] ?? ''] ?? null;

        return $group && $value ? $group.': '.$value : 'Разрешённое значение';
    }

    private static function activeLabel(string $title, bool $isActive): string
    {
        return $isActive ? $title : $title.' (Неактивно)';
    }

    private static function activeAction(): Action
    {
        return Action::make('toggle_active')
            ->label(fn (ProductOptionTemplate $record): string => $record->is_active ? 'Деактивировать' : 'Активировать')
            ->requiresConfirmation()
            ->modalDescription(fn (ProductOptionTemplate $record): string => sprintf(
                'Товары, варианты и элементы шаблона не изменятся. Использование: товары — %d, варианты — %d, значения — %d.',
                $record->products_count ?? $record->products()->count(),
                $record->variants_count ?? $record->variants()->count(),
                $record->items_count ?? $record->items()->count(),
            ))
            ->authorize(fn (ProductOptionTemplate $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->action(function (ProductOptionTemplate $record): void {
                /** @var User $actor */
                $actor = auth()->user();
                app(ProductOptionAdminService::class)->setTemplateActive($actor, $record, ! $record->is_active);
            });
    }
}
