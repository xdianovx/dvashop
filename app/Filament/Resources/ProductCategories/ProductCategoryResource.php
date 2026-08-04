<?php

namespace App\Filament\Resources\ProductCategories;

use App\Filament\Actions\CatalogLifecycleActions;
use App\Filament\Resources\ProductCategories\Pages\CreateProductCategory;
use App\Filament\Resources\ProductCategories\Pages\EditProductCategory;
use App\Filament\Resources\ProductCategories\Pages\ListProductCategories;
use App\Filament\Schemas\SeoSchema;
use App\Models\ProductCategory;
use App\Services\Catalog\CatalogStructureAdminService;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProductCategoryResource extends Resource
{
    protected static ?string $model = ProductCategory::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-folder';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return 'Каталог';
    }

    public static function getModelLabel(): string
    {
        return 'категория магазина';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Категории магазина';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('parent_id')
                ->label('Родительская категория')
                ->searchable()
                ->preload()
                ->options(self::parentOptions(...))
                ->nullable(),
            TextInput::make('title')
                ->label('Название')
                ->required()
                ->maxLength(255),
            TextInput::make('slug')
                ->label('Slug')
                ->maxLength(255)
                ->helperText('Можно оставить пустым — будет создан из названия.'),
            TextInput::make('full_slug')
                ->label('Полный slug')
                ->disabled()
                ->dehydrated(false),
            TextInput::make('depth')
                ->label('Глубина')
                ->disabled()
                ->dehydrated(false),
            TextInput::make('position')
                ->label('Позиция')
                ->numeric()
                ->default(0)
                ->required(),
            Toggle::make('is_active')
                ->label('Активна')
                ->default(true)
                ->disabled(fn (?ProductCategory $record): bool => $record?->exists === true)
                ->dehydrated(fn (?ProductCategory $record): bool => $record?->exists !== true)
                ->helperText('Для существующей категории используйте подтверждаемое действие в таблице.'),
            SeoSchema::section(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->columns([
                TextColumn::make('title')
                    ->label('Название')
                    ->state(fn (ProductCategory $record): string => $record->display_title)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('full_slug')
                    ->label('Полный slug')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('parent.title')
                    ->label('Родитель')
                    ->sortable(),
                TextColumn::make('position')
                    ->label('Позиция')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Активна')
                    ->boolean(),
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
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Активность')
                    ->trueLabel('Только активные')
                    ->falseLabel('Только неактивные'),
                TernaryFilter::make('noindex')
                    ->label('Индексация')
                    ->trueLabel('Только noindex')
                    ->falseLabel('Только индексируемые'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                CatalogLifecycleActions::toggleActive(),
                DeleteAction::make()->using(fn (ProductCategory $record) => app(CatalogStructureAdminService::class)->deleteCategory($record)),
                RestoreAction::make()->using(fn (ProductCategory $record) => app(CatalogStructureAdminService::class)->restoreCategory($record)),
                ForceDeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->hidden(),
                    RestoreBulkAction::make()->hidden(),
                    ForceDeleteBulkAction::make()->hidden(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('parent')
            ->withCount(['children', 'products', 'partTypes'])
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    /** @return array<int|string, string> */
    public static function parentOptions(?ProductCategory $record = null): array
    {
        return ProductCategory::query()
            ->when($record?->exists, fn (Builder $query): Builder => $query
                ->whereKeyNot($record->getKey())
                ->whereNotIn('id', $record->descendantIds()))
            ->orderBy('full_slug')
            ->get()
            ->mapWithKeys(fn (ProductCategory $category): array => [
                $category->getKey() => $category->display_title.' · '.$category->full_slug,
            ])
            ->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductCategories::route('/'),
            'create' => CreateProductCategory::route('/create'),
            'edit' => EditProductCategory::route('/{record}/edit'),
        ];
    }
}
