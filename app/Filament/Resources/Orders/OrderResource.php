<?php

namespace App\Filament\Resources\Orders;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-clipboard-document-list';

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
                        ->options(OrderStatus::options())
                        ->required(),
                    TextInput::make('subtotal')
                        ->label('Сумма товаров')
                        ->prefix('₽')
                        ->disabled(),
                    TextInput::make('total')
                        ->label('Итого')
                        ->prefix('₽')
                        ->disabled(),
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
                    TextInput::make('delivery_city')
                        ->label('Город')
                        ->disabled(),
                    TextInput::make('delivery_address')
                        ->label('Адрес')
                        ->disabled()
                        ->columnSpanFull(),
                    Textarea::make('comment')
                        ->label('Комментарий')
                        ->disabled()
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make('Состав заказа')
                ->schema([
                    Repeater::make('items')
                        ->label('Товары')
                        ->relationship()
                        ->schema([
                            TextInput::make('title')
                                ->label('Товар')
                                ->disabled()
                                ->columnSpanFull(),
                            TextInput::make('sku')
                                ->label('SKU')
                                ->disabled(),
                            TextInput::make('quantity')
                                ->label('Кол-во')
                                ->disabled(),
                            TextInput::make('price')
                                ->label('Цена')
                                ->prefix('₽')
                                ->disabled(),
                            TextInput::make('total')
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
                TextColumn::make('number')
                    ->label('Номер')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus | string | null $state): string => $state instanceof OrderStatus ? $state->label() : (OrderStatus::tryFrom((string) $state)?->label() ?? '—')),
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
                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(OrderStatus::options()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
            'edit' => EditOrder::route('/{record}/edit'),
        ];
    }
}
