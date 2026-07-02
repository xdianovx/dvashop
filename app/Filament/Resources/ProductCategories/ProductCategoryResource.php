<?php

namespace App\Filament\Resources\ProductCategories;

use App\Filament\Resources\ProductCategories\Pages\CreateProductCategory;
use App\Filament\Resources\ProductCategories\Pages\EditProductCategory;
use App\Filament\Resources\ProductCategories\Pages\ListProductCategories;
use App\Models\ProductCategory;
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
use Filament\Forms\Components\Textarea;
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

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-folder';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return 'Каталог';
    }

    public static function getModelLabel(): string
    {
        return 'категория товаров';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Категории товаров';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('parent_id')
                ->label('Родительская категория')
                ->searchable()
                ->preload()
                ->options(fn (?ProductCategory $record): array => ProductCategory::query()
                    ->when($record?->exists, fn (Builder $query): Builder => $query
                        ->whereKeyNot($record->getKey())
                        ->whereNotIn('id', $record->descendantIds()))
                    ->orderBy('full_slug')
                    ->get()
                    ->mapWithKeys(fn (ProductCategory $category): array => [
                        $category->getKey() => $category->display_title.' · '.$category->full_slug,
                    ])
                    ->all())
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
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
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
