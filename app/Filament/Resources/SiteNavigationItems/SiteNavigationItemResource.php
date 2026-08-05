<?php

namespace App\Filament\Resources\SiteNavigationItems;

use App\Enums\NavigationLinkType;
use App\Enums\NavigationZone;
use App\Filament\Resources\SiteNavigationItems\Pages\CreateSiteNavigationItem;
use App\Filament\Resources\SiteNavigationItems\Pages\EditSiteNavigationItem;
use App\Filament\Resources\SiteNavigationItems\Pages\ListSiteNavigationItems;
use App\Filament\Resources\SiteNavigationItems\Pages\ViewSiteNavigationItem;
use App\Models\SiteNavigationItem;
use App\Models\User;
use App\Services\Settings\SiteNavigationAdminService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class SiteNavigationItemResource extends Resource
{
    protected static ?string $model = SiteNavigationItem::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bars-3';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationGroup(): ?string
    {
        return 'Настройки';
    }

    public static function getNavigationLabel(): string
    {
        return 'Навигация сайта';
    }

    public static function getModelLabel(): string
    {
        return 'пункт навигации';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Навигация сайта';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Стабильный код')
                    ->required()
                    ->maxLength(255)
                    ->disabledOn('edit')
                    ->dehydrated(),
                Select::make('zone')
                    ->label('Зона')
                    ->options(NavigationZone::options())
                    ->required(),
                TextInput::make('title')
                    ->label('Название')
                    ->required()
                    ->maxLength(255),
                Select::make('link_type')
                    ->label('Тип ссылки')
                    ->options(NavigationLinkType::options())
                    ->default(NavigationLinkType::Route->value)
                    ->live()
                    ->required(),
                TextInput::make('route_name')
                    ->label('Имя маршрута')
                    ->visible(fn (Get $get): bool => $get('link_type') === NavigationLinkType::Route->value)
                    ->required(fn (Get $get): bool => $get('link_type') === NavigationLinkType::Route->value)
                    ->maxLength(255),
                TextInput::make('url')
                    ->label('Абсолютный URL')
                    ->visible(fn (Get $get): bool => $get('link_type') === NavigationLinkType::Url->value)
                    ->required(fn (Get $get): bool => $get('link_type') === NavigationLinkType::Url->value)
                    ->url()
                    ->maxLength(255),
                TextInput::make('position')
                    ->label('Позиция')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->default(0)
                    ->required(),
                Toggle::make('open_in_new_tab')->label('Открывать в новой вкладке')->default(false),
                Toggle::make('is_active')->label('Активен')->default(true),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('zone')
            ->reorderable(
                'position',
                fn (ListSiteNavigationItems $livewire): bool => $livewire->mayReorderCurrentZone(),
            )
            ->authorizeReorder(fn (): bool => auth()->user()?->can('reorder', SiteNavigationItem::class) ?? false)
            ->columns([
                TextColumn::make('zone')
                    ->label('Зона')
                    ->formatStateUsing(fn (NavigationZone|string $state): string => $state instanceof NavigationZone
                        ? $state->label()
                        : (NavigationZone::tryFrom($state)?->label() ?? $state))
                    ->sortable(),
                TextColumn::make('title')->label('Название')->searchable()->sortable(),
                TextColumn::make('link_type')
                    ->label('Тип ссылки')
                    ->badge()
                    ->formatStateUsing(fn (NavigationLinkType|string $state): string => $state instanceof NavigationLinkType
                        ? $state->label()
                        : (NavigationLinkType::tryFrom($state)?->label() ?? $state)),
                TextColumn::make('destination')
                    ->label('Маршрут / URL')
                    ->state(fn (SiteNavigationItem $record): string => $record->route_name ?? $record->url ?? '—')
                    ->wrap(),
                IconColumn::make('is_active')->label('Активен')->boolean(),
                TextColumn::make('position')->label('Позиция')->numeric()->sortable(),
                TextColumn::make('updated_at')->label('Обновлён')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('zone')->label('Зона')->options(NavigationZone::options()),
                TernaryFilter::make('is_active')
                    ->label('Активность')
                    ->trueLabel('Только активные')
                    ->falseLabel('Только неактивные'),
                SelectFilter::make('link_type')->label('Тип ссылки')->options(NavigationLinkType::options()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('toggle_active')
                    ->label(fn (SiteNavigationItem $record): string => $record->is_active ? 'Деактивировать' : 'Активировать')
                    ->icon(fn (SiteNavigationItem $record): string => $record->is_active ? 'heroicon-o-pause' : 'heroicon-o-play')
                    ->color(fn (SiteNavigationItem $record): string => $record->is_active ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->authorize(fn (SiteNavigationItem $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->action(fn (SiteNavigationItem $record): SiteNavigationItem => app(SiteNavigationAdminService::class)
                        ->setActive(self::actor(), $record, ! $record->is_active)),
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->using(function (SiteNavigationItem $record): void {
                        app(SiteNavigationAdminService::class)->delete(self::actor(), $record);
                    }),
            ])
            ->emptyStateHeading('Пункты навигации не найдены');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSiteNavigationItems::route('/'),
            'create' => CreateSiteNavigationItem::route('/create'),
            'view' => ViewSiteNavigationItem::route('/{record}'),
            'edit' => EditSiteNavigationItem::route('/{record}/edit'),
        ];
    }

    public static function actor(): User
    {
        $actor = Filament::auth()->user();

        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
