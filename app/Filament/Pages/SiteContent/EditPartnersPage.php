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

class EditPartnersPage extends SiteContentEditorPage
{
    protected static ?string $slug = 'content/site-pages/partners';

    public function getTitle(): string
    {
        return 'Партнёрам';
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
                    ->description('Кнопки, изображения и их назначения зафиксированы макетом.')
                    ->schema([
                        Hidden::make('page.id'),
                        TextInput::make('page.title')->label('Заголовок')->required()->maxLength(255),
                        Textarea::make('page.subtitle')->label('Подзаголовок')->rows(4)->maxLength(5000)->columnSpanFull(),
                    ]),
                Section::make('Четыре преимущества')
                    ->schema([
                        Repeater::make('benefits')
                            ->label('Преимущества')
                            ->schema([
                                Hidden::make('id'),
                                Hidden::make('_label')->dehydrated(false),
                                TextInput::make('title')->label('Название')->maxLength(500),
                            ])
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->collapsible()
                            ->itemLabel(fn (array $state): string => (string) ($state['_label'] ?? $state['title'] ?? 'Преимущество'))
                            ->columnSpanFull(),
                    ]),
                Section::make('Четыре формата сотрудничества')
                    ->schema([
                        Hidden::make('cooperation.id'),
                        TextInput::make('cooperation.title')->label('Заголовок блока')->maxLength(255),
                        Repeater::make('cooperation.items')
                            ->label('Форматы сотрудничества')
                            ->schema([
                                Hidden::make('id'),
                                Hidden::make('_label')->dehydrated(false),
                                TextInput::make('title')->label('Название')->maxLength(500),
                            ])
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->collapsible()
                            ->itemLabel(fn (array $state): string => (string) ($state['_label'] ?? $state['title'] ?? 'Формат сотрудничества'))
                            ->columnSpanFull(),
                    ]),
                Section::make('Пять фактов о компании')
                    ->schema([
                        Hidden::make('about.id'),
                        TextInput::make('about.title')->label('Заголовок блока')->maxLength(255),
                        Repeater::make('about.items')
                            ->label('Факты')
                            ->schema([
                                Hidden::make('id'),
                                Hidden::make('_label')->dehydrated(false),
                                Textarea::make('text')->label('Текст')->rows(3)->maxLength(10000),
                            ])
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->collapsible()
                            ->itemLabel(fn (array $state): string => (string) ($state['_label'] ?? 'Факт о компании'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    protected function loadState(SitePageContentAdminService $service): array
    {
        return $service->partnersState();
    }

    protected function persistState(SitePageContentAdminService $service, User $actor, array $data): void
    {
        $service->savePartners($actor, $data);
    }

    protected function successNotificationTitle(): string
    {
        return 'Страница «Партнёрам» сохранена';
    }
}
