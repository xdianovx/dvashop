<?php

namespace App\Filament\Resources\VehicleModels;

use App\Filament\Actions\CatalogLifecycleActions;
use App\Filament\Resources\VehicleModels\Pages\CreateVehicleModel;
use App\Filament\Resources\VehicleModels\Pages\EditVehicleModel;
use App\Filament\Resources\VehicleModels\Pages\ListVehicleModels;
use App\Filament\Schemas\SeoSchema;
use App\Models\VehicleModel;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class VehicleModelResource extends Resource
{
    protected static ?string $model = VehicleModel::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return 'Авто';
    }

    public static function getModelLabel(): string
    {
        return 'модель авто';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Модели авто';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('vehicle_make_id')
                ->label('Марка')
                ->relationship('make', 'title')
                ->searchable()
                ->preload()
                ->required(),
            TextInput::make('title')
                ->label('Название')
                ->required()
                ->maxLength(255),
            TextInput::make('slug')
                ->label('Slug')
                ->maxLength(255)
                ->helperText('Уникален внутри выбранной марки. Можно оставить пустым.'),
            TextInput::make('norm_key')
                ->label('Norm key')
                ->maxLength(255)
                ->helperText('Уникален внутри выбранной марки. Можно оставить пустым.'),
            TextInput::make('position')
                ->label('Позиция')
                ->numeric()
                ->default(0)
                ->required(),
            Toggle::make('is_active')
                ->label('Активна')
                ->default(true)
                ->disabled(fn (?VehicleModel $record): bool => $record?->exists === true)
                ->dehydrated(fn (?VehicleModel $record): bool => $record?->exists !== true)
                ->helperText('Для существующей модели используйте подтверждаемое действие в таблице.'),
            SeoSchema::section(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->columns([
                TextColumn::make('make.title')
                    ->label('Марка')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('title')
                    ->label('Модель')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('norm_key')
                    ->label('Norm key')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('generations_count')
                    ->label('Поколения')
                    ->counts('generations')
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
                SelectFilter::make('vehicle_make_id')
                    ->label('Марка')
                    ->relationship('make', 'title')
                    ->searchable()
                    ->preload(),
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
                DeleteAction::make()->using(fn (VehicleModel $record) => app(CatalogStructureAdminService::class)->deleteVehicle($record)),
                RestoreAction::make()->using(fn (VehicleModel $record) => app(CatalogStructureAdminService::class)->restoreVehicle($record)),
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
            ->with('make')
            ->withCount('generations')
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVehicleModels::route('/'),
            'create' => CreateVehicleModel::route('/create'),
            'edit' => EditVehicleModel::route('/{record}/edit'),
        ];
    }
}
