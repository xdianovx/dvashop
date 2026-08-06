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

class EditHowPage extends SiteContentEditorPage
{
    protected static ?string $slug = 'content/site-pages/how';

    public function getTitle(): string
    {
        return 'Как мы работаем';
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
                Section::make('Шесть шагов')
                    ->description('Номера, порядок и иконки шагов зафиксированы в макете.')
                    ->schema([
                        Repeater::make('steps')
                            ->label('Шаги работы')
                            ->schema([
                                Hidden::make('id'),
                                Hidden::make('_label')->dehydrated(false),
                                TextInput::make('title')->label('Название')->maxLength(500),
                                Textarea::make('text')->label('Описание')->rows(4)->maxLength(10000),
                            ])
                            ->columns(2)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->collapsible()
                            ->itemLabel(fn (array $state): string => (string) ($state['_label'] ?? 'Шаг'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    protected function loadState(SitePageContentAdminService $service): array
    {
        return $service->howState();
    }

    protected function persistState(SitePageContentAdminService $service, User $actor, array $data): void
    {
        $service->saveHow($actor, $data);
    }

    protected function successNotificationTitle(): string
    {
        return 'Страница «Как мы работаем» сохранена';
    }
}
