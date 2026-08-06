<?php

namespace App\Filament\Pages\SiteContent;

use App\Enums\AdminPermission;
use App\Filament\Support\SiteContentEditorPage;
use App\Models\User;
use App\Services\SiteContent\SitePageContentAdminService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EditFaqPage extends SiteContentEditorPage
{
    protected static ?string $slug = 'content/site-pages/faq';

    public function getTitle(): string
    {
        return 'Вопросы и ответы';
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
                Section::make('FAQ')
                    ->description('Категории и вопросы редактируются вместе. Удаление из списка выполняется как soft delete после сохранения формы.')
                    ->schema([
                        Repeater::make('categories')
                            ->label('Категории вопросов')
                            ->schema([
                                Hidden::make('id'),
                                TextInput::make('title')->label('Название категории')->required()->maxLength(255),
                                Toggle::make('is_active')->label('Показывать категорию'),
                                Repeater::make('items')
                                    ->label('Вопросы категории')
                                    ->schema([
                                        Hidden::make('id'),
                                        TextInput::make('question')->label('Вопрос')->required()->maxLength(500)->columnSpanFull(),
                                        Textarea::make('answer')->label('Ответ')->required()->rows(5)->maxLength(5000)->columnSpanFull(),
                                        Toggle::make('is_active')->label('Показывать вопрос')->default(true),
                                        Toggle::make('is_featured')->label('Показывать в избранных')->default(false),
                                    ])
                                    ->columns(2)
                                    ->defaultItems(0)
                                    ->reorderable()
                                    ->deleteAction(
                                        fn (Action $action): Action => $action
                                            ->requiresConfirmation()
                                            ->modalHeading('Удалить вопрос из формы?')
                                            ->modalDescription('После сохранения страницы существующий вопрос будет перемещён в корзину.'),
                                    )
                                    ->collapsible()
                                    ->itemLabel(fn (array $state): string => (string) ($state['question'] ?? 'Новый вопрос'))
                                    ->addActionLabel('Добавить вопрос')
                                    ->columnSpanFull(),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->reorderable()
                            ->deleteAction(
                                fn (Action $action): Action => $action
                                    ->requiresConfirmation()
                                    ->modalHeading('Удалить категорию из формы?')
                                    ->modalDescription('После сохранения страницы существующая категория и её вопросы будут перемещены в корзину.'),
                            )
                            ->collapsible()
                            ->itemLabel(fn (array $state): string => (string) ($state['title'] ?? 'Новая категория'))
                            ->addActionLabel('Добавить категорию')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    protected function loadState(SitePageContentAdminService $service): array
    {
        return $service->faqState();
    }

    protected function persistState(SitePageContentAdminService $service, User $actor, array $data): void
    {
        $service->saveFaq($actor, $data);
    }

    protected function successNotificationTitle(): string
    {
        return 'Вопросы и ответы сохранены';
    }
}
