<?php

namespace App\Filament\Resources\PartTypes;

use App\Filament\Resources\PartTypes\Pages\CreatePartType;
use App\Filament\Resources\PartTypes\Pages\EditPartType;
use App\Filament\Resources\PartTypes\Pages\ListPartTypes;
use App\Models\PartType;
use App\Models\ProductCategory;
use App\Services\Catalog\PartTypeTreeService;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
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
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PartTypeResource extends Resource
{
    protected static ?string $model = PartType::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $recordTitleAttribute = 'full_title';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return 'Каталог';
    }

    public static function getNavigationLabel(): string
    {
        return 'Типы деталей';
    }

    public static function getModelLabel(): string
    {
        return 'тип детали';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Типы деталей';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('parent_id')
                ->label('Родительский тип')
                ->searchable()
                ->preload()
                ->options(fn (?PartType $record): array => self::parentOptions($record))
                ->nullable(),
            TextInput::make('title')
                ->label('Название')
                ->required()
                ->maxLength(255),
            TextInput::make('full_slug')
                ->label('Полный slug')
                ->disabled()
                ->dehydrated(false),
            TextInput::make('full_title')
                ->label('Полное название')
                ->disabled()
                ->dehydrated(false),
            TextInput::make('depth')
                ->label('Глубина')
                ->disabled()
                ->dehydrated(false),
            Select::make('product_category_id')
                ->label('Категория магазина')
                ->searchable()
                ->preload()
                ->options(fn (): array => self::productCategoryOptions())
                ->nullable(),
            TextInput::make('default_image_key')
                ->label('Ключ дефолтного изображения')
                ->maxLength(255)
                ->nullable(),
            TextInput::make('position')
                ->label('Позиция')
                ->numeric()
                ->integer()
                ->default(0)
                ->required(),
            Toggle::make('is_active')
                ->label('Активен')
                ->default(true),
            TextInput::make('meta_title')
                ->label('Meta title')
                ->maxLength(255),
            Textarea::make('meta_description')
                ->label('Meta description')
                ->rows(3)
                ->columnSpanFull(),
        ]);
    }

    /** @return array<int, string> */
    public static function parentOptions(?PartType $record = null): array
    {
        $excludedIds = [];

        if ($record?->exists) {
            $excludedIds = [
                (int) $record->getKey(),
                ...app(PartTypeTreeService::class)->descendantIds($record),
            ];
        }

        return PartType::query()
            ->when($excludedIds !== [], fn (Builder $query): Builder => $query->whereNotIn('id', $excludedIds))
            ->orderBy('full_title')
            ->pluck('full_title', 'id')
            ->all();
    }

    /** @return array<int, string> */
    public static function productCategoryOptions(): array
    {
        return ProductCategory::query()
            ->orderBy('full_slug')
            ->get(['id', 'title', 'full_slug'])
            ->mapWithKeys(fn (ProductCategory $category): array => [
                $category->getKey() => $category->title.' · '.$category->full_slug,
            ])
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->columns([
                TextColumn::make('title')
                    ->label('Название')
                    ->state(fn (PartType $record): string => str_repeat('— ', max(0, $record->depth)).$record->title)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('full_title')
                    ->label('Полное название')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('full_slug')
                    ->label('Полный slug')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('productCategory.full_slug')
                    ->label('Категория магазина')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('default_image_key')
                    ->label('Ключ изображения')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('products_count')
                    ->label('Товаров')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean(),
                TextColumn::make('position')
                    ->label('Позиция')
                    ->sortable(),
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
                SelectFilter::make('product_category_id')
                    ->label('Категория магазина')
                    ->options(fn (): array => ProductCategory::query()
                        ->orderBy('full_slug')
                        ->pluck('full_slug', 'id')
                        ->all()),
                TernaryFilter::make('parent_id')
                    ->label('Уровень')
                    ->trueLabel('Только корневые')
                    ->falseLabel('Только дочерние')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNull('parent_id'),
                        false: fn (Builder $query): Builder => $query->whereNotNull('parent_id'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ])
            ->with('productCategory')
            ->withCount('products');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPartTypes::route('/'),
            'create' => CreatePartType::route('/create'),
            'edit' => EditPartType::route('/{record}/edit'),
        ];
    }
}
