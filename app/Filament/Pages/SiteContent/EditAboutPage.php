<?php

namespace App\Filament\Pages\SiteContent;

use App\Enums\AdminPermission;
use App\Filament\Support\SiteContentEditorPage;
use App\Models\User;
use App\Services\SiteContent\SitePageContentAdminService;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EditAboutPage extends SiteContentEditorPage
{
    protected static ?string $slug = 'content/site-pages/about';

    public function getTitle(): string
    {
        return 'О нас';
    }

    protected static function viewPermissions(): array
    {
        return [AdminPermission::ViewStaticContent];
    }

    protected static function updatePermissions(): array
    {
        return [AdminPermission::ManageStaticContent];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->disabled(fn (): bool => ! $this->canUpdate())
            ->components([
                Section::make('Первый экран')
                    ->schema([
                        Hidden::make('hero.id'),
                        TextInput::make('hero.label')->label('Надзаголовок')->maxLength(255),
                        TextInput::make('hero.title')->label('Заголовок')->maxLength(255),
                        Textarea::make('hero.body')->label('Описание')->rows(5)->maxLength(10000)->columnSpanFull(),
                    ])->columns(2),
                Section::make('Показатели')
                    ->description('Два показателя и их порядок фиксированы макетом.')
                    ->schema([
                        Repeater::make('metrics')
                            ->label('Показатели компании')
                            ->schema([
                                Hidden::make('id'),
                                Hidden::make('_label')->dehydrated(false),
                                TextInput::make('title')->label('Значение и подпись')->maxLength(500),
                                Textarea::make('text')->label('Описание')->rows(3)->maxLength(10000),
                            ])
                            ->columns(2)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->collapsible()
                            ->itemLabel(fn (array $state): string => (string) ($state['_label'] ?? $state['title'] ?? 'Показатель'))
                            ->columnSpanFull(),
                    ]),
                Section::make('Технологии точности')
                    ->schema([
                        Hidden::make('technologies.id'),
                        TextInput::make('technologies.title')->label('Заголовок')->maxLength(255),
                        Textarea::make('technologies.subtitle')->label('Подзаголовок')->rows(3)->maxLength(5000)->columnSpanFull(),
                        Repeater::make('technologies.items')
                            ->label('Технологии')
                            ->schema([
                                Hidden::make('id'),
                                Hidden::make('_label')->dehydrated(false),
                                Textarea::make('text')->label('Описание')->rows(4)->maxLength(10000),
                            ])
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->collapsible()
                            ->itemLabel(fn (array $state): string => (string) ($state['_label'] ?? 'Технология'))
                            ->columnSpanFull(),
                    ])->columns(2),
                Section::make('Наша цель')
                    ->schema([
                        Hidden::make('goal.id'),
                        TextInput::make('goal.label')->label('Заголовок блока')->maxLength(255),
                        Textarea::make('goal.body')->label('Текст')->rows(5)->maxLength(10000)->columnSpanFull(),
                    ]),
            ]);
    }

    protected function loadState(SitePageContentAdminService $service): array
    {
        return $service->aboutState();
    }

    protected function persistState(SitePageContentAdminService $service, User $actor, array $data): void
    {
        $service->saveAbout($actor, $data);
    }

    protected function successNotificationTitle(): string
    {
        return 'Страница «О нас» сохранена';
    }
}
