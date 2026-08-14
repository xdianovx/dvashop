<?php

namespace App\Filament\Resources\StorefrontInquiries;

use App\Enums\StorefrontInquiryType;
use App\Filament\Resources\StorefrontInquiries\Pages\ListStorefrontInquiries;
use App\Filament\Resources\StorefrontInquiries\Pages\ViewStorefrontInquiry;
use App\Models\StorefrontInquiry;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class StorefrontInquiryResource extends Resource
{
    protected static ?string $model = StorefrontInquiry::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 40;

    public static function getNavigationGroup(): ?string
    {
        return 'Продажи';
    }

    public static function getModelLabel(): string
    {
        return 'заявка';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Заявки';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Заявка')
                ->schema([
                    TextInput::make('type')
                        ->label('Тип')
                        ->formatStateUsing(fn (mixed $state): string => $state instanceof StorefrontInquiryType
                            ? $state->label()
                            : (StorefrontInquiryType::tryFrom((string) $state)?->label() ?? '—'))
                        ->disabled(),
                    TextInput::make('created_at')->label('Создана')->disabled(),
                    TextInput::make('name')->label('Имя')->disabled(),
                    TextInput::make('phone')->label('Телефон')->disabled(),
                    TextInput::make('email')->label('Email')->disabled(),
                    Textarea::make('message')->label('Сообщение')->rows(4)->disabled()->columnSpanFull(),
                    TextInput::make('source_code')->label('Источник')->disabled(),
                    TextInput::make('source_url')->label('Страница')->disabled()->columnSpanFull(),
                ])->columns(2),
            Section::make('Снимок товара')
                ->schema([
                    TextInput::make('product_title_snapshot')->label('Товар')->disabled(),
                    TextInput::make('variant_sku_snapshot')->label('SKU')->disabled(),
                    Textarea::make('options_snapshot')
                        ->label('Опции')
                        ->formatStateUsing(fn (mixed $state, ?StorefrontInquiry $record): string => $record?->optionSummary() ?: '—')
                        ->disabled()
                        ->columnSpanFull(),
                ])->columns(2),
            Section::make('Доставка')
                ->schema([
                    TextInput::make('email_delivery_status')->label('Email')->disabled(),
                    TextInput::make('email_sent_at')->label('Email отправлен')->disabled(),
                    TextInput::make('email_failed_at')->label('Ошибка Email')->disabled(),
                    TextInput::make('bitrix_delivery_status')->label('Bitrix')->disabled(),
                    TextInput::make('bitrix_sent_at')->label('Bitrix отправлен')->disabled(),
                    TextInput::make('bitrix_failed_at')->label('Ошибка Bitrix')->disabled(),
                    TextInput::make('bitrix_entity_id')->label('ID в Bitrix')->disabled(),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('type')->label('Тип')->badge()->formatStateUsing(fn (mixed $state): string => $state instanceof StorefrontInquiryType
                    ? $state->label()
                    : (StorefrontInquiryType::tryFrom((string) $state)?->label() ?? '—')),
                TextColumn::make('name')->label('Имя')->searchable(),
                TextColumn::make('phone')->label('Телефон')->searchable(),
                TextColumn::make('product_title_snapshot')->label('Товар')->limit(40)->toggleable(),
                TextColumn::make('email_delivery_status')->label('Email')->badge(),
                TextColumn::make('bitrix_delivery_status')->label('Bitrix')->badge(),
                TextColumn::make('created_at')->label('Создана')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
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
            'index' => ListStorefrontInquiries::route('/'),
            'view' => ViewStorefrontInquiry::route('/{record}'),
        ];
    }
}
