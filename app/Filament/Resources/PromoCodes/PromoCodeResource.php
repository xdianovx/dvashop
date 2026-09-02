<?php

namespace App\Filament\Resources\PromoCodes;

use App\Enums\PromoDiscountType;
use App\Filament\Resources\PromoCodes\Pages\CreatePromoCode;
use App\Filament\Resources\PromoCodes\Pages\EditPromoCode;
use App\Filament\Resources\PromoCodes\Pages\ListPromoCodes;
use App\Filament\Resources\PromoCodes\Pages\ViewPromoCode;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PromoCode;
use App\Services\Promotions\PromoCodeAdminService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PromoCodeResource extends Resource
{
    protected static ?string $model = PromoCode::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $recordTitleAttribute = 'code';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return 'Продажи';
    }

    public static function getModelLabel(): string
    {
        return 'промокод';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Промокоды';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Основное')
                ->schema([
                    TextInput::make('code')
                        ->label('Код')
                        ->required()
                        ->minLength(3)
                        ->maxLength(64)
                        ->regex('/\A[A-Za-z0-9_-]+\z/')
                        ->disabled(fn (?PromoCode $record): bool => $record?->redemptions()->exists() ?? false)
                        ->dehydrated()
                        ->helperText('Латинские буквы, цифры, дефис и подчёркивание. Регистр не учитывается.')
                        ->suffixAction(Action::make('generateCode')
                            ->label('Сгенерировать')
                            ->icon('heroicon-m-sparkles')
                            ->action(fn (Set $set): mixed => $set(
                                'code',
                                app(PromoCodeAdminService::class)->generateUniqueCode(),
                            ))),
                    TextInput::make('name')
                        ->label('Название')
                        ->required()
                        ->maxLength(255),
                    Textarea::make('description')
                        ->label('Описание для сотрудников')
                        ->rows(3)
                        ->maxLength(5000)
                        ->columnSpanFull(),
                    Toggle::make('is_active')
                        ->label('Активен')
                        ->default(true),
                ])
                ->columns(2),
            Section::make('Скидка и ограничения')
                ->schema([
                    Select::make('discount_type')
                        ->label('Тип скидки')
                        ->options(PromoDiscountType::options())
                        ->default(PromoDiscountType::Percentage->value)
                        ->live()
                        ->required(),
                    TextInput::make('discount_value')
                        ->label(fn (Get $get): string => $get('discount_type') === PromoDiscountType::Fixed->value ? 'Сумма скидки' : 'Процент скидки')
                        ->numeric()
                        ->minValue(0.0001)
                        ->maxValue(fn (Get $get): ?int => $get('discount_type') === PromoDiscountType::Percentage->value ? 100 : null)
                        ->suffix(fn (Get $get): string => $get('discount_type') === PromoDiscountType::Fixed->value ? '₽' : '%')
                        ->required(),
                    TextInput::make('max_discount_amount')
                        ->label('Максимальная скидка')
                        ->numeric()
                        ->minValue(0.01)
                        ->suffix('₽')
                        ->visible(fn (Get $get): bool => $get('discount_type') === PromoDiscountType::Percentage->value),
                    TextInput::make('minimum_eligible_subtotal')
                        ->label('Минимальная сумма подходящих товаров')
                        ->numeric()
                        ->minValue(0)
                        ->suffix('₽'),
                    TextInput::make('usage_limit')
                        ->label('Лимит использований')
                        ->integer()
                        ->minValue(1)
                        ->helperText('Пусто — без общего лимита.'),
                    Toggle::make('allow_sale_items')
                        ->label('Разрешить товары со скидкой')
                        ->default(false),
                    DateTimePicker::make('starts_at')
                        ->label('Начало действия')
                        ->seconds(false),
                    DateTimePicker::make('ends_at')
                        ->label('Окончание действия')
                        ->seconds(false)
                        ->afterOrEqual('starts_at'),
                ])
                ->columns(2),
            Section::make('Область действия')
                ->description('Выбранные товары, категории и типы деталей объединяются по правилу «ИЛИ». Категории и типы применяются только к выбранному уровню, без потомков.')
                ->schema([
                    Toggle::make('applies_to_all')
                        ->label('Весь каталог')
                        ->default(true)
                        ->live()
                        ->columnSpanFull(),
                    Select::make('product_ids')
                        ->label('Товары')
                        ->multiple()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => self::productSearch($search))
                        ->getOptionLabelsUsing(fn (array $values): array => self::productLabels($values))
                        ->visible(fn (Get $get): bool => ! (bool) $get('applies_to_all')),
                    Select::make('product_category_ids')
                        ->label('Категории')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->options(fn (): array => ProductCategory::query()->orderBy('full_slug')->pluck('full_slug', 'id')->all())
                        ->visible(fn (Get $get): bool => ! (bool) $get('applies_to_all')),
                    Select::make('part_type_ids')
                        ->label('Типы деталей')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->options(fn (): array => PartType::query()->orderBy('full_title')->pluck('full_title', 'id')->all())
                        ->visible(fn (Get $get): bool => ! (bool) $get('applies_to_all')),
                ])
                ->columns(2),
            Section::make('Статистика')
                ->schema([
                    Placeholder::make('current_status_display')
                        ->label('Текущий статус')
                        ->content(fn (?PromoCode $record): string => $record?->currentStatusLabel() ?? '—'),
                    Placeholder::make('usage_display')
                        ->label('Использовано')
                        ->content(fn (?PromoCode $record): string => (string) ($record?->active_redemptions_count ?? 0)),
                    Placeholder::make('usage_limit_display')
                        ->label('Лимит')
                        ->content(fn (?PromoCode $record): string => $record?->usage_limit === null ? 'Без лимита' : (string) $record->usage_limit),
                    Placeholder::make('usage_available_display')
                        ->label('Доступно')
                        ->content(fn (?PromoCode $record): string => $record?->usage_limit === null
                            ? 'Без ограничений'
                            : (string) max(0, $record->usage_limit - (int) ($record->active_redemptions_count ?? 0))),
                    Placeholder::make('discount_total_display')
                        ->label('Сумма выданных скидок')
                        ->content(fn (?PromoCode $record): string => number_format((float) ($record?->discount_total_sum ?? 0), 2, ',', ' ').' ₽'),
                ])
                ->columns(3)
                ->visible(fn (?PromoCode $record): bool => $record?->exists === true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('code')->label('Код')->searchable()->copyable()->sortable(),
                TextColumn::make('name')->label('Название')->searchable()->sortable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->state(fn (PromoCode $record): string => $record->currentStatusLabel())
                    ->badge(),
                TextColumn::make('discount_value')
                    ->label('Скидка')
                    ->formatStateUsing(fn (mixed $state, PromoCode $record): string => $record->discount_type === PromoDiscountType::Percentage
                        ? rtrim(rtrim(number_format((float) $state, 4, '.', ''), '0'), '.').' %'
                        : number_format((float) $state, 2, ',', ' ').' ₽'),
                TextColumn::make('active_redemptions_count')
                    ->label('Использовано / лимит')
                    ->formatStateUsing(fn (mixed $state, PromoCode $record): string => (int) $state.' / '.($record->usage_limit ?? '∞'))
                    ->sortable(),
                TextColumn::make('discount_total_sum')->label('Выдано скидок')->money('RUB')->sortable(),
                IconColumn::make('applies_to_all')->label('Весь каталог')->boolean(),
                TextColumn::make('starts_at')->label('Начало')->dateTime('d.m.Y H:i')->sortable()->toggleable(),
                TextColumn::make('ends_at')->label('Окончание')->dateTime('d.m.Y H:i')->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('discount_type')->label('Тип скидки')->options(PromoDiscountType::options()),
                SelectFilter::make('current_status')
                    ->label('Текущий статус')
                    ->options([
                        'active' => 'Активен',
                        'scheduled' => 'Запланирован',
                        'expired' => 'Истёк',
                        'exhausted' => 'Лимит исчерпан',
                        'disabled' => 'Отключён',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => self::filterByCurrentStatus($query, $data['value'] ?? null)),
                TernaryFilter::make('is_active')->label('Активность'),
                TrashedFilter::make()->label('Архив'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make()
                    ->label('Архивировать')
                    ->using(fn (PromoCode $record) => app(PromoCodeAdminService::class)->archive(auth()->user(), $record)),
                RestoreAction::make()
                    ->using(fn (PromoCode $record) => app(PromoCodeAdminService::class)->restore(auth()->user(), $record)),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount('activeRedemptions')
            ->withSum('redemptions as discount_total_sum', 'discount_amount')
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /** @return array<int|string, string> */
    private static function productSearch(string $search): array
    {
        return Product::query()
            ->where(function (Builder $query) use ($search): void {
                $query->where('title', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            })
            ->orderBy('title')
            ->limit(50)
            ->get(['id', 'title', 'sku'])
            ->mapWithKeys(fn (Product $product): array => [$product->getKey() => self::productLabel($product)])
            ->all();
    }

    /** @param array<int|string> $values @return array<int|string, string> */
    private static function productLabels(array $values): array
    {
        return Product::query()
            ->whereKey($values)
            ->get(['id', 'title', 'sku'])
            ->mapWithKeys(fn (Product $product): array => [$product->getKey() => self::productLabel($product)])
            ->all();
    }

    private static function productLabel(Product $product): string
    {
        return $product->title.(filled($product->sku) ? " · {$product->sku}" : '');
    }

    private static function filterByCurrentStatus(Builder $query, mixed $status): Builder
    {
        if (! is_string($status) || $status === '') {
            return $query;
        }

        $activeUsageSql = '(select count(*) from promo_code_redemptions where promo_code_redemptions.promo_code_id = promo_codes.id and promo_code_redemptions.released_at is null)';

        return match ($status) {
            'scheduled' => $query->whereNull('deleted_at')->where('is_active', true)->where('starts_at', '>', now()),
            'expired' => $query->whereNull('deleted_at')->where('is_active', true)->where('ends_at', '<', now()),
            'disabled' => $query->whereNull('deleted_at')->where('is_active', false),
            'exhausted' => $query
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->where(fn (Builder $query): Builder => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn (Builder $query): Builder => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
                ->whereNotNull('usage_limit')
                ->whereRaw("{$activeUsageSql} >= promo_codes.usage_limit"),
            'active' => $query
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->where(fn (Builder $query): Builder => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn (Builder $query): Builder => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
                ->where(fn (Builder $query): Builder => $query
                    ->whereNull('usage_limit')
                    ->orWhereRaw("{$activeUsageSql} < promo_codes.usage_limit")),
            default => $query,
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPromoCodes::route('/'),
            'create' => CreatePromoCode::route('/create'),
            'view' => ViewPromoCode::route('/{record}'),
            'edit' => EditPromoCode::route('/{record}/edit'),
        ];
    }
}
