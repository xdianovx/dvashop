<?php

namespace App\Filament\Resources\ProductOptionGroups;

use App\Filament\Resources\ProductOptionGroups\Pages\CreateProductOptionGroup;
use App\Filament\Resources\ProductOptionGroups\Pages\EditProductOptionGroup;
use App\Filament\Resources\ProductOptionGroups\Pages\ListProductOptionGroups;
use App\Filament\Resources\ProductOptionGroups\Pages\ViewProductOptionGroup;
use App\Filament\Resources\ProductOptionGroups\RelationManagers\ValuesRelationManager;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplateItem;
use App\Models\User;
use App\Services\Catalog\ProductOptionAdminService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductOptionGroupResource extends Resource
{
    protected static ?string $model = ProductOptionGroup::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?int $navigationSort = 45;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationGroup(): ?string
    {
        return 'Каталог';
    }

    public static function getNavigationLabel(): string
    {
        return 'Группы опций';
    }

    public static function getModelLabel(): string
    {
        return 'группа опций';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Группы опций';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->label('Название')
                ->required()
                ->maxLength(255),
            TextInput::make('slug')
                ->label('Slug')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->helperText('После использования группы slug и code изменить нельзя.'),
            TextInput::make('code')
                ->label('Code')
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->nullable(),
            Select::make('input_type')
                ->label('Тип выбора')
                ->options(['radio' => 'Переключатель', 'select' => 'Список'])
                ->default('radio')
                ->required(),
            Select::make('applies_to')
                ->label('Область применения')
                ->options(self::appliesToOptions())
                ->default(ProductOptionGroup::APPLIES_ALL)
                ->required(),
            TextInput::make('position')
                ->label('Позиция')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->default(0)
                ->required(),
            Toggle::make('is_required')
                ->label('Обязательная')
                ->default(false),
            Toggle::make('is_active')
                ->label('Активна')
                ->default(true),
            Textarea::make('description')
                ->label('Описание')
                ->rows(4)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->reorderable('position', fn (): bool => auth()->user()?->can('reorder', ProductOptionGroup::class) ?? false)
            ->authorizeReorder(fn (): bool => auth()->user()?->can('reorder', ProductOptionGroup::class) ?? false)
            ->columns([
                TextColumn::make('title')->label('Название')->searchable()->sortable(),
                TextColumn::make('slug')->label('Slug')->searchable()->sortable(),
                TextColumn::make('code')->label('Code')->searchable()->toggleable(),
                TextColumn::make('applies_to')
                    ->label('Применение')
                    ->formatStateUsing(fn (string $state): string => self::appliesToOptions()[$state] ?? $state)
                    ->sortable(),
                TextColumn::make('values_count')->label('Значений')->numeric()->sortable(),
                TextColumn::make('active_values_count')->label('Активных значений')->numeric()->sortable(),
                TextColumn::make('template_items_count')->label('В шаблонах')->numeric()->sortable(),
                TextColumn::make('variant_option_values_count')->label('В вариантах')->numeric()->sortable(),
                IconColumn::make('is_required')->label('Обязательная')->boolean(),
                IconColumn::make('is_active')->label('Активна')->boolean(),
                TextColumn::make('position')->label('Позиция')->numeric()->sortable(),
                TextColumn::make('created_at')->label('Создано')->dateTime('d.m.Y H:i')->sortable()->toggleable(),
                TextColumn::make('updated_at')->label('Обновлено')->dateTime('d.m.Y H:i')->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('applies_to')->label('Область применения')->options(self::appliesToOptions()),
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
            ->emptyStateHeading('Группы опций не найдены');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->addSelect([
                'template_items_count' => ProductOptionTemplateItem::query()
                    ->selectRaw('COUNT(DISTINCT product_option_template_id)')
                    ->whereColumn('product_option_group_id', 'product_option_groups.id'),
            ])
            ->withCount([
                'values',
                'values as active_values_count' => fn (Builder $query): Builder => $query->where('is_active', true),
                'variantOptionValues',
            ]);
    }

    public static function getRelations(): array
    {
        return [ValuesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductOptionGroups::route('/'),
            'create' => CreateProductOptionGroup::route('/create'),
            'view' => ViewProductOptionGroup::route('/{record}'),
            'edit' => EditProductOptionGroup::route('/{record}/edit'),
        ];
    }

    /** @return array<string, string> */
    public static function appliesToOptions(): array
    {
        return [
            ProductOptionGroup::APPLIES_ALL => 'Все товары',
            ProductOptionGroup::APPLIES_AUTO_PART => 'Автодетали',
            ProductOptionGroup::APPLIES_GENERIC => 'Обычные товары',
        ];
    }

    private static function activeAction(): Action
    {
        return Action::make('toggle_active')
            ->label(fn (ProductOptionGroup $record): string => $record->is_active ? 'Деактивировать' : 'Активировать')
            ->icon(fn (ProductOptionGroup $record): string => $record->is_active ? 'heroicon-o-pause' : 'heroicon-o-play')
            ->color(fn (ProductOptionGroup $record): string => $record->is_active ? 'warning' : 'success')
            ->requiresConfirmation()
            ->modalDescription(fn (ProductOptionGroup $record): string => sprintf(
                'Связи не удаляются. Использование: шаблоны — %d, варианты — %d.',
                $record->template_items_count
                    ?? $record->templateItems()->distinct()->count('product_option_template_id'),
                $record->variant_option_values_count ?? $record->variantOptionValues()->count(),
            ))
            ->authorize(fn (ProductOptionGroup $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->action(function (ProductOptionGroup $record): void {
                /** @var User $actor */
                $actor = auth()->user();
                app(ProductOptionAdminService::class)->setGroupActive($actor, $record, ! $record->is_active);
            });
    }
}
