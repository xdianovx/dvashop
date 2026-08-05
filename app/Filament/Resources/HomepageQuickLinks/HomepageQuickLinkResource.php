<?php

namespace App\Filament\Resources\HomepageQuickLinks;

use App\Enums\HomepageQuickLinkCode;
use App\Enums\NavigationLinkType;
use App\Filament\Resources\HomepageQuickLinks\Pages\EditHomepageQuickLink;
use App\Filament\Resources\HomepageQuickLinks\Pages\ListHomepageQuickLinks;
use App\Filament\Resources\HomepageQuickLinks\Pages\ViewHomepageQuickLink;
use App\Models\HomepageQuickLink;
use App\Models\User;
use App\Services\Homepage\HomepageContentAdminService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class HomepageQuickLinkResource extends Resource
{
    protected static ?string $model = HomepageQuickLink::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-link';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationGroup(): ?string
    {
        return 'Главная страница';
    }

    public static function getNavigationLabel(): string
    {
        return 'Быстрые ссылки';
    }

    public static function getModelLabel(): string
    {
        return 'быструю ссылку';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Быстрые ссылки';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('Системный код')
                ->formatStateUsing(fn (HomepageQuickLinkCode|string|null $state): string => $state instanceof HomepageQuickLinkCode ? $state->value : (string) $state)
                ->disabled()
                ->dehydrated(),
            TextInput::make('title')->label('Название')->required()->maxLength(255),
            Select::make('link_type')
                ->label('Тип перехода')
                ->options(NavigationLinkType::options())
                ->placeholder('Без перехода')
                ->live()
                ->afterStateUpdated(function (?string $state, Set $set): void {
                    if ($state !== NavigationLinkType::Route->value) {
                        $set('route_name', null);
                    }
                    if ($state !== NavigationLinkType::Url->value) {
                        $set('url', null);
                    }
                }),
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
                ->maxLength(2048),
            Toggle::make('open_in_new_tab')->label('Открывать в новой вкладке'),
            TextInput::make('position')->label('Позиция')->numeric()->integer()->minValue(0)->required(),
            Toggle::make('is_active')->label('Активна'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->reorderable('position')
            ->authorizeReorder(fn (): bool => auth()->user()?->can('reorder', HomepageQuickLink::class) ?? false)
            ->columns([
                TextColumn::make('code')->label('Код')->formatStateUsing(fn (HomepageQuickLinkCode|string $state): string => $state instanceof HomepageQuickLinkCode ? $state->value : $state),
                TextColumn::make('title')->label('Название')->searchable(),
                TextColumn::make('link_type')->label('Тип')->badge()->placeholder('Без перехода')->formatStateUsing(fn (NavigationLinkType|string|null $state): string => $state instanceof NavigationLinkType ? $state->label() : (NavigationLinkType::tryFrom((string) $state)?->label() ?? 'Без перехода')),
                TextColumn::make('destination')->label('Маршрут / URL')->state(fn (HomepageQuickLink $record): string => $record->route_name ?? $record->url ?? '—')->wrap(),
                IconColumn::make('open_in_new_tab')->label('Новая вкладка')->boolean(),
                IconColumn::make('is_active')->label('Активна')->boolean(),
                TextColumn::make('position')->label('Позиция')->numeric()->sortable(),
                TextColumn::make('updated_at')->label('Обновлена')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Активность')->trueLabel('Только активные')->falseLabel('Только неактивные'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('toggle_active')
                    ->label(fn (HomepageQuickLink $record): string => $record->is_active ? 'Деактивировать' : 'Активировать')
                    ->requiresConfirmation()
                    ->authorize(fn (HomepageQuickLink $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->action(fn (HomepageQuickLink $record): HomepageQuickLink => app(HomepageContentAdminService::class)
                        ->setQuickLinkActive(self::actor(), $record, ! $record->is_active)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHomepageQuickLinks::route('/'),
            'view' => ViewHomepageQuickLink::route('/{record}'),
            'edit' => EditHomepageQuickLink::route('/{record}/edit'),
        ];
    }

    public static function actor(): User
    {
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
