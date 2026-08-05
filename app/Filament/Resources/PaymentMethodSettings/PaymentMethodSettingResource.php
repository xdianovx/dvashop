<?php

namespace App\Filament\Resources\PaymentMethodSettings;

use App\Enums\PaymentMethod;
use App\Filament\Resources\PaymentMethodSettings\Pages\EditPaymentMethodSetting;
use App\Filament\Resources\PaymentMethodSettings\Pages\ListPaymentMethodSettings;
use App\Filament\Resources\PaymentMethodSettings\Pages\ViewPaymentMethodSetting;
use App\Models\PaymentMethodSetting;
use App\Models\User;
use App\Services\Orders\PaymentMethodSettingsAdminService;
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

class PaymentMethodSettingResource extends Resource
{
    protected static ?string $model = PaymentMethodSetting::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?int $navigationSort = 25;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationGroup(): ?string
    {
        return 'Продажи';
    }

    public static function getNavigationLabel(): string
    {
        return 'Способы оплаты';
    }

    public static function getModelLabel(): string
    {
        return 'способ оплаты';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Способы оплаты';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('Системный код')
                ->formatStateUsing(fn (PaymentMethod|string|null $state): string => $state instanceof PaymentMethod ? $state->value : (string) $state)
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
            ->authorizeReorder(fn (): bool => auth()->user()?->can('reorder', PaymentMethodSetting::class) ?? false)
            ->columns([
                TextColumn::make('code')
                    ->label('Код')
                    ->formatStateUsing(fn (PaymentMethod|string $state): string => $state instanceof PaymentMethod ? $state->value : $state)
                    ->searchable(),
                TextColumn::make('title')->label('Название')->searchable()->sortable(),
                TextColumn::make('description')->label('Описание')->limit(80)->wrap()->toggleable(),
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
                    ->label(fn (PaymentMethodSetting $record): string => $record->is_active ? 'Деактивировать' : 'Активировать')
                    ->icon(fn (PaymentMethodSetting $record): string => $record->is_active ? 'heroicon-o-pause' : 'heroicon-o-play')
                    ->color(fn (PaymentMethodSetting $record): string => $record->is_active ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->authorize(fn (PaymentMethodSetting $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->action(fn (PaymentMethodSetting $record): PaymentMethodSetting => app(PaymentMethodSettingsAdminService::class)
                        ->setActive(self::actor(), $record, ! $record->is_active)),
            ])
            ->emptyStateHeading('Способы оплаты не найдены');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentMethodSettings::route('/'),
            'view' => ViewPaymentMethodSetting::route('/{record}'),
            'edit' => EditPaymentMethodSetting::route('/{record}/edit'),
        ];
    }

    public static function actor(): User
    {
        $actor = Filament::auth()->user();

        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
