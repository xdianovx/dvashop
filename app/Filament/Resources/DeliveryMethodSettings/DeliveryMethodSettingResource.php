<?php

namespace App\Filament\Resources\DeliveryMethodSettings;

use App\Enums\DeliveryMethod;
use App\Filament\Resources\DeliveryMethodSettings\Pages\EditDeliveryMethodSetting;
use App\Filament\Resources\DeliveryMethodSettings\Pages\ListDeliveryMethodSettings;
use App\Filament\Resources\DeliveryMethodSettings\Pages\ViewDeliveryMethodSetting;
use App\Models\DeliveryMethodSetting;
use App\Models\User;
use App\Services\Orders\DeliveryMethodSettingsAdminService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class DeliveryMethodSettingResource extends Resource
{
    protected static ?string $model = DeliveryMethodSetting::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationGroup(): ?string
    {
        return 'Продажи';
    }

    public static function getNavigationLabel(): string
    {
        return 'Способы доставки';
    }

    public static function getModelLabel(): string
    {
        return 'способ доставки';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Способы доставки';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('Системный код')
                ->formatStateUsing(fn (DeliveryMethod|string|null $state): string => $state instanceof DeliveryMethod ? $state->value : (string) $state)
                ->disabled()
                ->dehydrated(),
            TextInput::make('title')
                ->label('Название')
                ->required()
                ->maxLength(255),
            Textarea::make('description')
                ->label('Описание')
                ->rows(5)
                ->maxLength(5000)
                ->columnSpanFull(),
            TextInput::make('base_price')
                ->label('Базовая стоимость')
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->prefix('₽')
                ->required(),
            TextInput::make('position')
                ->label('Позиция')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->required(),
            Toggle::make('is_active')->label('Активен'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->reorderable('position')
            ->authorizeReorder(fn (): bool => auth()->user()?->can('reorder', DeliveryMethodSetting::class) ?? false)
            ->columns([
                TextColumn::make('code')
                    ->label('Код')
                    ->formatStateUsing(fn (DeliveryMethod|string $state): string => $state instanceof DeliveryMethod ? $state->value : $state)
                    ->searchable(),
                TextColumn::make('title')->label('Название')->searchable()->sortable(),
                TextColumn::make('description')->label('Описание')->limit(80)->wrap()->toggleable(),
                TextColumn::make('base_price')->label('Базовая стоимость')->money('RUB')->sortable(),
                IconColumn::make('is_active')->label('Активен')->boolean(),
                TextColumn::make('position')->label('Позиция')->numeric()->sortable(),
                TextColumn::make('updated_at')->label('Обновлён')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Активность')
                    ->trueLabel('Только активные')
                    ->falseLabel('Только неактивные'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('toggle_active')
                    ->label(fn (DeliveryMethodSetting $record): string => $record->is_active ? 'Деактивировать' : 'Активировать')
                    ->icon(fn (DeliveryMethodSetting $record): string => $record->is_active ? 'heroicon-o-pause' : 'heroicon-o-play')
                    ->color(fn (DeliveryMethodSetting $record): string => $record->is_active ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->authorize(fn (DeliveryMethodSetting $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->action(fn (DeliveryMethodSetting $record): DeliveryMethodSetting => app(DeliveryMethodSettingsAdminService::class)
                        ->setActive(self::actor(), $record, ! $record->is_active)),
            ])
            ->emptyStateHeading('Способы доставки не найдены');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeliveryMethodSettings::route('/'),
            'view' => ViewDeliveryMethodSetting::route('/{record}'),
            'edit' => EditDeliveryMethodSetting::route('/{record}/edit'),
        ];
    }

    public static function actor(): User
    {
        $actor = Filament::auth()->user();

        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
