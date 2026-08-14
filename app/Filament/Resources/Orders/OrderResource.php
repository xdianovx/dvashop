<?php

namespace App\Filament\Resources\Orders;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return 'Продажи';
    }

    public static function getModelLabel(): string
    {
        return 'заказ';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Заказы';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Заказ')
                ->schema([
                    TextInput::make('number')
                        ->label('Номер')
                        ->disabled(),
                    Select::make('status')
                        ->label('Статус')
                        ->options(fn (?Order $record): array => $record?->status->transitionOptions() ?? OrderStatus::options())
                        ->required(),
                    DateTimePicker::make('placed_at')
                        ->label('Оформлен')
                        ->disabled(),
                    TextInput::make('subtotal')
                        ->label('Сумма товаров')
                        ->prefix('₽')
                        ->disabled(),
                    TextInput::make('delivery_price')
                        ->label('Доставка')
                        ->prefix('₽')
                        ->disabled(),
                    TextInput::make('total')
                        ->label('Итого')
                        ->prefix('₽')
                        ->disabled(),
                    Textarea::make('manager_comment')
                        ->label('Комментарий менеджера')
                        ->rows(3)
                        ->maxLength(5000)
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make('Покупатель')
                ->schema([
                    TextInput::make('customer_name')
                        ->label('ФИО')
                        ->disabled(),
                    TextInput::make('customer_phone')
                        ->label('Телефон')
                        ->disabled(),
                    TextInput::make('customer_email')
                        ->label('Email')
                        ->disabled(),
                    TextInput::make('customer_city')
                        ->label('Город')
                        ->disabled(),
                    TextInput::make('customer_address')
                        ->label('Адрес')
                        ->disabled()
                        ->columnSpanFull(),
                    Textarea::make('customer_comment')
                        ->label('Комментарий')
                        ->disabled()
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make('Оплата и доставка')
                ->schema([
                    Select::make('payment_method')
                        ->label('Способ оплаты')
                        ->options(PaymentMethod::options())
                        ->disabled(),
                    Select::make('payment_status')
                        ->label('Статус оплаты')
                        ->options(fn (?Order $record): array => $record?->payment_status->transitionOptions() ?? PaymentStatus::options())
                        ->required()
                        ->live(),
                    DateTimePicker::make('paid_at')
                        ->label('Оплачен')
                        ->disabled(),
                    Select::make('delivery_method')
                        ->label('Способ доставки')
                        ->options(DeliveryMethod::options())
                        ->disabled(),
                    DateTimePicker::make('customer_email_sent_at')
                        ->label('Email клиенту отправлен')
                        ->disabled(),
                    DateTimePicker::make('manager_email_sent_at')
                        ->label('Email менеджеру отправлен')
                        ->disabled(),
                    DateTimePicker::make('bitrix_sent_at')
                        ->label('Bitrix отправлен')
                        ->disabled(),
                    TextInput::make('bitrix_entity_id')
                        ->label('ID в Bitrix')
                        ->disabled(),
                ])
                ->columns(2),
            Section::make('Состав заказа')
                ->schema([
                    Repeater::make('items')
                        ->label('Товары')
                        ->relationship()
                        ->schema([
                            TextInput::make('title_snapshot')
                                ->label('Товар')
                                ->disabled()
                                ->columnSpanFull(),
                            TextInput::make('sku_snapshot')
                                ->label('SKU')
                                ->disabled(),
                            Textarea::make('options_snapshot')
                                ->label('Выбранные опции')
                                ->formatStateUsing(fn (mixed $state): string => self::optionSummary($state))
                                ->disabled()
                                ->columnSpanFull(),
                            TextInput::make('image_snapshot')
                                ->label('Снимок изображения')
                                ->disabled()
                                ->columnSpanFull(),
                            TextInput::make('quantity')
                                ->label('Кол-во')
                                ->disabled(),
                            TextInput::make('price_snapshot')
                                ->label('Цена')
                                ->prefix('₽')
                                ->disabled(),
                            TextInput::make('total_snapshot')
                                ->label('Сумма')
                                ->prefix('₽')
                                ->disabled(),
                        ])
                        ->columns(4)
                        ->disabled()
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('number')
                    ->label('Номер')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus|string|null $state): string => $state instanceof OrderStatus ? $state->label() : (OrderStatus::tryFrom((string) $state)?->label() ?? '—')),
                TextColumn::make('customer_name')
                    ->label('Клиент')
                    ->searchable(),
                TextColumn::make('customer_phone')
                    ->label('Телефон')
                    ->searchable(),
                TextColumn::make('customer_email')
                    ->label('Email')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('items_count')
                    ->label('Товары')
                    ->counts('items')
                    ->sortable(),
                TextColumn::make('total')
                    ->label('Итого')
                    ->money('RUB')
                    ->sortable(),
                TextColumn::make('payment_method')
                    ->label('Оплата')
                    ->formatStateUsing(fn (PaymentMethod|string|null $state): string => $state instanceof PaymentMethod ? $state->label() : (PaymentMethod::tryFrom((string) $state)?->label() ?? '—'))
                    ->toggleable(),
                TextColumn::make('payment_status')
                    ->label('Статус оплаты')
                    ->badge()
                    ->formatStateUsing(fn (PaymentStatus|string|null $state): string => $state instanceof PaymentStatus ? $state->label() : (PaymentStatus::tryFrom((string) $state)?->label() ?? '—')),
                TextColumn::make('delivery_method')
                    ->label('Доставка')
                    ->formatStateUsing(fn (DeliveryMethod|string|null $state): string => $state instanceof DeliveryMethod ? $state->label() : (DeliveryMethod::tryFrom((string) $state)?->label() ?? '—'))
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(OrderStatus::options()),
                SelectFilter::make('payment_status')
                    ->label('Статус оплаты')
                    ->options(PaymentStatus::options()),
                SelectFilter::make('payment_method')
                    ->label('Способ оплаты')
                    ->options(PaymentMethod::options()),
                SelectFilter::make('delivery_method')
                    ->label('Способ доставки')
                    ->options(DeliveryMethod::options()),
                Filter::make('created_at')
                    ->label('Дата создания')
                    ->schema([
                        DatePicker::make('from')->label('С'),
                        DatePicker::make('until')->label('По'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
            'edit' => EditOrder::route('/{record}/edit'),
        ];
    }

    public static function actor(): User
    {
        $actor = Filament::auth()->user();

        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private static function optionSummary(mixed $options): string
    {
        return collect(is_array($options) ? $options : [])
            ->map(function (mixed $option, string|int $key): ?string {
                if (is_array($option) && filled($option['value'] ?? null)) {
                    return (string) (($option['group'] ?? null) ?: $key).': '.$option['value'];
                }

                return is_scalar($option) && filled((string) $option)
                    ? (string) $key.': '.$option
                    : null;
            })
            ->filter()
            ->implode('; ');
    }
}
