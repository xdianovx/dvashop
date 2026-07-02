<?php

namespace App\Filament\Resources\VehicleGenerations;

use App\Filament\Resources\VehicleGenerations\Pages\CreateVehicleGeneration;
use App\Filament\Resources\VehicleGenerations\Pages\EditVehicleGeneration;
use App\Filament\Resources\VehicleGenerations\Pages\ListVehicleGenerations;
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
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
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

class VehicleGenerationResource extends Resource
{
    protected static ?string $model = VehicleGeneration::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): ?string
    {
        return 'Авто';
    }

    public static function getModelLabel(): string
    {
        return 'поколение авто';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Поколения авто';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('vehicle_model_id')
                ->label('Модель')
                ->relationship('model', 'title')
                ->getOptionLabelFromRecordUsing(fn ($record): string => $record->display_title)
                ->searchable(['title', 'slug', 'norm_key'])
                ->preload()
                ->required(),
            TextInput::make('title')
                ->label('Название')
                ->required()
                ->maxLength(255),
            TextInput::make('slug')
                ->label('Slug')
                ->maxLength(255)
                ->helperText('Уникален внутри выбранной модели. Можно оставить пустым.'),
            TextInput::make('norm_key')
                ->label('Norm key')
                ->maxLength(255)
                ->helperText('Уникален внутри выбранной модели. Можно оставить пустым.'),
            TextInput::make('years_label')
                ->label('Годы выпуска')
                ->maxLength(255),
            TextInput::make('body')
                ->label('Кузов')
                ->maxLength(255),
            FileUpload::make('image')
                ->label('Изображение')
                ->image()
                ->directory('vehicle-generations'),
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
                ImageColumn::make('image')
                    ->label('Фото')
                    ->square()
                    ->toggleable(),
                TextColumn::make('model.make.title')
                    ->label('Марка')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('model.title')
                    ->label('Модель')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('title')
                    ->label('Поколение')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('years_label')
                    ->label('Годы')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('body')
                    ->label('Кузов')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('norm_key')
                    ->label('Norm key')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('position')
                    ->label('Позиция')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Активна')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('vehicle_model_id')
                    ->label('Модель')
                    ->relationship('model', 'title')
                    ->searchable()
                    ->preload(),
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
            'index' => ListVehicleGenerations::route('/'),
            'create' => CreateVehicleGeneration::route('/create'),
            'edit' => EditVehicleGeneration::route('/{record}/edit'),
        ];
    }
}
