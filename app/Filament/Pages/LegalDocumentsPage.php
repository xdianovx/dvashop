<?php

namespace App\Filament\Pages;

use App\Enums\AdminPermission;
use App\Enums\LegalDocumentContentType;
use App\Models\User;
use App\Services\Legal\LegalDocumentAdminService;
use App\Services\Legal\LegalDocumentPdfStorage;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
            ? 'Четыре системных документа. Для каждого выберите страницу или PDF-файл.'
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
                    ->description('Пустая страница автоматически выключается. Для публикации PDF загрузите файл.')
                    ->schema([
                        Repeater::make('documents')
                            ->label('Документы')
                            ->minItems(4)
                            ->maxItems(4)
                            ->schema([
                                Hidden::make('id'),
                                Hidden::make('_label')->dehydrated(false),
                                TextInput::make('title')
                                    ->label('Название')
                                    ->placeholder('Документ не заполнен')
                                    ->required()
                                    ->maxLength(255),
                                Toggle::make('is_active')->label('Показывать на сайте')->live(),
                                Radio::make('content_type')
                                    ->label('Тип документа')
                                    ->options(LegalDocumentContentType::options())
                                    ->default(LegalDocumentContentType::Page->value)
                                    ->required()->live()->inline(),
                                FileUpload::make('pdf_path')
                                    ->label('PDF-файл')
                                    ->disk('public')
                                    ->directory(LegalDocumentPdfStorage::DIRECTORY)
                                    ->visibility('public')
                                    ->acceptedFileTypes(['application/pdf'])
                                    ->maxSize(LegalDocumentPdfStorage::MAX_SIZE_KB)
                                    ->storeFiles(false)
                                    ->required(fn (Get $get): bool => (bool) $get('is_active'))
                                    ->visible(fn (Get $get): bool => $get('content_type') === LegalDocumentContentType::Pdf->value)
                                    ->helperText('Только PDF, до 10 МБ. Старый файл удаляется после успешного сохранения замены или страницы.')
                                    ->columnSpanFull(),
                                RichEditor::make('body')
                                    ->label('Содержимое')
                                    ->visible(fn (Get $get): bool => $get('content_type') !== LegalDocumentContentType::Pdf->value)
                                    ->placeholder('Документ не заполнен')
                                    ->toolbarButtons([
                                        ['paragraph', 'h2', 'h3', 'h4'],
                                        ['bold', 'italic', 'underline', 'strike', 'link'],
                                        ['bulletList', 'orderedList', 'blockquote'],
                                        ['alignStart', 'alignCenter', 'alignEnd', 'alignJustify'],
                                        ['horizontalRule', 'table'],
                                        ['undo', 'redo'],
                                    ])
                                    ->floatingToolbars([
                                        'table' => [
                                            'tableAddColumnBefore', 'tableAddColumnAfter', 'tableDeleteColumn',
                                            'tableAddRowBefore', 'tableAddRowAfter', 'tableDeleteRow',
                                            'tableMergeCells', 'tableSplitCell',
                                            'tableToggleHeaderRow', 'tableToggleHeaderCell', 'tableDelete',
                                        ],
                                    ])
                                    ->fileAttachments(false)
                                    ->maxLength(60000)
                                    ->columnSpanFull(),
                            ])
                            ->columns(2)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->collapsible()
                            ->itemLabel(fn (array $state): string => (string) ($state['_label'] ?? 'Системный документ'))
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
            $service->validateFormPayload($this->data ?? []);
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
