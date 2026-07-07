<?php

namespace App\Filament\Resources\VehicleMakes;

use App\Filament\Resources\VehicleMakes\Pages\CreateVehicleMake;
use App\Filament\Resources\VehicleMakes\Pages\EditVehicleMake;
use App\Filament\Resources\VehicleMakes\Pages\ListVehicleMakes;
use App\Models\VehicleMake;
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
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class VehicleMakeResource extends Resource
{
    protected static ?string $model = VehicleMake::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-truck';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return 'Авто';
    }

    public static function getModelLabel(): string
    {
        return 'марка авто';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Марки авто';
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
                ->maxLength(255)
                ->helperText('Можно оставить пустым — будет создан из названия.'),
            TextInput::make('norm_key')
                ->label('Norm key')
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->helperText('Нормализованный ключ для импорта/поиска. Можно оставить пустым.'),
            FileUpload::make('image')
                ->label('Изображение')
                ->disk('public')
                ->directory('uploads/vehicles/makes/manual')
                ->image()
                ->imageEditor()
                ->imagePreviewHeight('160')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->maxSize((int) ceil(config('media.max_source_size', 15 * 1024 * 1024) / 1024)),
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
                TextColumn::make('title')
                    ->label('Название')
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
                TextColumn::make('models_count')
                    ->label('Модели')
                    ->counts('models')
                    ->sortable(),
                TextColumn::make('position')
                    ->label('Позиция')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Активна')
                    ->boolean(),
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
            'index' => ListVehicleMakes::route('/'),
            'create' => CreateVehicleMake::route('/create'),
            'edit' => EditVehicleMake::route('/{record}/edit'),
        ];
    }
}
