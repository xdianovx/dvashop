<?php

namespace App\Filament\Pages\SiteContent;

use App\Enums\AdminPermission;
use App\Filament\Support\SiteContentEditorPage;
use App\Models\User;
use App\Services\SiteContent\SitePageContentAdminService;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class EditHomepagePage extends SiteContentEditorPage
{
    protected static ?string $slug = 'content/site-pages/home';

    public function getTitle(): string
    {
        return 'Главная';
    }

    protected static function viewPermissions(): array
    {
        return [AdminPermission::ViewHomepageContent];
    }

    protected static function updatePermissions(): array
    {
        return [AdminPermission::ManageHomepageContent];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->disabled(fn (): bool => ! $this->canUpdate())
            ->components([
                Section::make('Секции главной страницы')
                    ->description('Изменяйте названия и видимость четырёх системных секций. Состав и порядок фиксированы макетом.')
                    ->schema([
                        Repeater::make('sections')
                            ->label('Секции')
                            ->schema([
                                Hidden::make('id'),
                                Hidden::make('_label')->dehydrated(false),
                                TextInput::make('title')
                                    ->label('Название секции')
                                    ->maxLength(255),
                                Toggle::make('is_active')->label('Показывать секцию'),
                            ])
                            ->columns(2)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->collapsible()
                            ->itemLabel(fn (array $state): string => (string) (($state['title'] ?? null) ?: ($state['_label'] ?? 'Секция главной страницы')))
                            ->columnSpanFull(),
                    ]),
                Section::make('Быстрые ссылки')
                    ->description('Изменяйте подписи, назначение и порядок. Системный набор ссылок остаётся фиксированным.')
                    ->schema([
                        Repeater::make('quick_links')
                            ->label('Ссылки')
                            ->schema([
                                Hidden::make('id'),
                                Hidden::make('_label')->dehydrated(false),
                                TextInput::make('title')
                                    ->label('Название')
                                    ->required()
                                    ->maxLength(255),
                                Select::make('destination')
                                    ->label('Куда ведёт ссылка')
                                    ->options(SitePageContentAdminService::destinationOptions())
                                    ->required()
                                    ->live(),
                                TextInput::make('external_url')
                                    ->label('Адрес внешней ссылки')
                                    ->url()
                                    ->maxLength(2048)
                                    ->required(fn (Get $get): bool => $get('destination') === SitePageContentAdminService::DESTINATION_EXTERNAL)
                                    ->visible(fn (Get $get): bool => $get('destination') === SitePageContentAdminService::DESTINATION_EXTERNAL)
                                    ->columnSpanFull(),
                                Toggle::make('is_active')->label('Показывать на сайте'),
                            ])
                            ->columns(2)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable()
                            ->collapsible()
                            ->itemLabel(fn (array $state): string => (string) ($state['title'] ?? 'Быстрая ссылка'))
                            ->columnSpanFull(),
                    ]),
                Section::make('Витринные категории')
                    ->description('Изображение каждой карточки определяется её фиксированным назначением и не редактируется.')
                    ->schema([
                        Repeater::make('category_cards')
                            ->label('Карточки категорий')
                            ->schema([
                                Hidden::make('id'),
                                Hidden::make('_label')->dehydrated(false),
                                TextInput::make('title')
                                    ->label('Название')
                                    ->required()
                                    ->maxLength(255),
                                Select::make('destination')
                                    ->label('Куда ведёт карточка')
                                    ->options(SitePageContentAdminService::destinationOptions())
                                    ->required()
                                    ->live(),
                                TextInput::make('external_url')
                                    ->label('Адрес внешней ссылки')
                                    ->url()
                                    ->maxLength(2048)
                                    ->required(fn (Get $get): bool => $get('destination') === SitePageContentAdminService::DESTINATION_EXTERNAL)
                                    ->visible(fn (Get $get): bool => $get('destination') === SitePageContentAdminService::DESTINATION_EXTERNAL)
                                    ->columnSpanFull(),
                                Toggle::make('is_active')->label('Показывать на сайте'),
                            ])
                            ->columns(2)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable()
                            ->collapsible()
                            ->itemLabel(fn (array $state): string => (string) ($state['title'] ?? 'Витринная категория'))
                            ->columnSpanFull(),
                    ]),
                Section::make('Показатели компании')
                    ->description('Количество показателей, их порядок и иконки фиксированы макетом.')
                    ->schema([
                        Repeater::make('metrics')
                            ->label('Показатели')
                            ->schema([
                                Hidden::make('id'),
                                Hidden::make('_label')->dehydrated(false),
                                TextInput::make('prefix')->label('Префикс')->maxLength(32),
                                TextInput::make('value')->label('Значение')->required()->maxLength(64),
                                TextInput::make('suffix')->label('Суффикс')->maxLength(64),
                                Textarea::make('text')
                                    ->label('Описание')
                                    ->required()
                                    ->rows(3)
                                    ->maxLength(500)
                                    ->columnSpanFull(),
                            ])
                            ->columns(3)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->collapsible()
                            ->itemLabel(fn (array $state): string => implode(' ', array_filter([
                                $state['prefix'] ?? null,
                                $state['value'] ?? null,
                                $state['suffix'] ?? null,
                            ])) ?: 'Показатель компании')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    protected function loadState(SitePageContentAdminService $service): array
    {
        return $service->homepageState();
    }

    protected function persistState(SitePageContentAdminService $service, User $actor, array $data): void
    {
        $service->saveHomepage($actor, $data);
    }

    protected function successNotificationTitle(): string
    {
        return 'Главная страница сохранена';
    }
}
