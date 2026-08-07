<?php

namespace App\Filament\Pages;

use App\Enums\AdminPermission;
use App\Models\User;
use App\Services\Legal\LegalDocumentAdminService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class LegalDocumentsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-check';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'content/legal-documents';

    protected string $view = 'filament.pages.site-content.editor';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return 'Контент сайта';
    }

    public static function getNavigationLabel(): string
    {
        return 'Документы';
    }

    public function getTitle(): string
    {
        return 'Документы';
    }

    public function getSubheading(): ?string
    {
        return $this->canUpdate()
            ? 'Четыре системных документа. Создание, удаление и изменение типа документа запрещены.'
            : 'Режим просмотра: изменения и сохранение недоступны для вашей роли.';
    }

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            && $user->canPerformAdminAction(AdminPermission::ViewStaticContent);
    }

    public function canUpdate(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            && $user->canPerformAdminAction(AdminPermission::ManageStaticContent);
    }

    public function mount(LegalDocumentAdminService $service): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill($service->state());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->disabled(fn (): bool => ! $this->canUpdate())
            ->components([
                Section::make('Юридические документы')
                    ->description('Пустой документ автоматически остаётся выключенным. Содержимое хранится как безопасный обычный текст.')
                    ->schema([
                        Repeater::make('documents')
                            ->label('Документы')
                            ->schema([
                                Hidden::make('id'),
                                Hidden::make('_label')->dehydrated(false),
                                TextInput::make('title')
                                    ->label('Название')
                                    ->placeholder('Документ не заполнен')
                                    ->required()
                                    ->maxLength(255),
                                Toggle::make('is_active')->label('Показывать на сайте'),
                                Textarea::make('body')
                                    ->label('Содержимое')
                                    ->placeholder('Документ не заполнен')
                                    ->rows(14)
                                    ->maxLength(60000)
                                    ->columnSpanFull(),
                            ])
                            ->columns(2)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->collapsible()
                            ->itemLabel(fn (array $state): string => (string) ($state['title'] ?? $state['_label'] ?? 'Документ'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public function save(LegalDocumentAdminService $service): void
    {
        $actor = Filament::auth()->user();

        if (! $actor instanceof User || ! $this->canUpdate()) {
            throw new AuthorizationException('Недостаточно прав для сохранения документов.');
        }

        try {
            $service->save($actor, $this->form->getState());
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => [
                    str_starts_with($field, 'data.') ? $field : 'data.'.$field => $messages,
                ])
                ->all());
        }

        $this->form->fill($service->state());

        Notification::make()
            ->success()
            ->title('Документы сохранены')
            ->send();
    }
}
