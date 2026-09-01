<?php

namespace App\Filament\Pages\SiteContent;

use App\Enums\AdminPermission;
use App\Enums\HomepageStoryMediaType;
use App\Filament\Support\SiteContentEditorPage;
use App\Models\User;
use App\Services\SiteContent\SitePageContentAdminService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
                Section::make('Сторис')
                    ->description('Кружки и сторис можно добавлять, удалять и менять местами. Пустые кружки на сайте не показываются.')
                    ->schema([
                        Hidden::make('stories_section.id'),
                        Toggle::make('stories_section.is_active')->label('Показывать секцию'),
                        Repeater::make('stories')
                            ->label('Кружки')
                            ->schema([
                                Hidden::make('id'),
                                Hidden::make('_label')->dehydrated(false),
                                TextInput::make('title')
                                    ->label('Название кружка')
                                    ->required()
                                    ->maxLength(255),
                                FileUpload::make('cover_image_path')
                                    ->label('Обложка кружка')
                                    ->disk('public')
                                    ->visibility('public')
                                    ->directory('uploads/homepage/stories')
                                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                    ->maxSize(10240)
                                    ->image()
                                    ->imagePreviewHeight('160')
                                    ->required(fn (Get $get): bool => (bool) $get('is_active')),
                                Toggle::make('is_active')
                                    ->label('Показывать кружок')
                                    ->default(true),
                                Repeater::make('items')
                                    ->label('Сторис внутри кружка')
                                    ->schema([
                                        Hidden::make('id'),
                                        Hidden::make('_label')->dehydrated(false),
                                        Select::make('media_type')
                                            ->label('Тип')
                                            ->options(collect(HomepageStoryMediaType::cases())
                                                ->mapWithKeys(fn (HomepageStoryMediaType $type): array => [$type->value => $type->label()])
                                                ->all())
                                            ->default(HomepageStoryMediaType::Image->value)
                                            ->required()
                                            ->live()
                                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                                $set('image_media_path', null);
                                                $set('video_media_path', null);

                                                if ($state === HomepageStoryMediaType::Video->value) {
                                                    $set('duration_seconds', null);
                                                } else {
                                                    $set('duration_seconds', 10);
                                                }
                                            }),
                                        FileUpload::make('image_media_path')
                                            ->label('Изображение сторис')
                                            ->disk('public')
                                            ->visibility('public')
                                            ->directory('uploads/homepage/stories')
                                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                            ->maxSize(10240)
                                            ->image()
                                            ->imagePreviewHeight('320')
                                            ->previewable()
                                            ->openable()
                                            ->required(fn (Get $get): bool => $get('media_type') === HomepageStoryMediaType::Image->value)
                                            ->visible(fn (Get $get): bool => $get('media_type') === HomepageStoryMediaType::Image->value)
                                            ->columnSpanFull(),
                                        FileUpload::make('video_media_path')
                                            ->label('Видео сторис')
                                            ->helperText('MP4 или WebM, не более 90 МБ.')
                                            ->disk('public')
                                            ->visibility('public')
                                            ->directory('uploads/homepage/stories')
                                            ->acceptedFileTypes(['video/mp4', 'video/webm'])
                                            ->maxSize(92160)
                                            ->previewable()
                                            ->openable()
                                            ->required(fn (Get $get): bool => $get('media_type') === HomepageStoryMediaType::Video->value)
                                            ->visible(fn (Get $get): bool => $get('media_type') === HomepageStoryMediaType::Video->value)
                                            ->columnSpanFull(),
                                        TextInput::make('duration_seconds')
                                            ->label('Длительность, секунд')
                                            ->numeric()
                                            ->minValue(3)
                                            ->maxValue(60)
                                            ->default(10)
                                            ->required(fn (Get $get): bool => $get('media_type') === HomepageStoryMediaType::Image->value)
                                            ->visible(fn (Get $get): bool => $get('media_type') === HomepageStoryMediaType::Image->value),
                                        TextInput::make('alt_text')
                                            ->label('Альтернативный текст')
                                            ->maxLength(255),
                                        TextInput::make('cta_label')
                                            ->label('Текст кнопки')
                                            ->maxLength(255),
                                        TextInput::make('cta_url')
                                            ->label('Ссылка кнопки')
                                            ->helperText('Внутренняя ссылка или абсолютный адрес http/https.')
                                            ->maxLength(2048)
                                            ->columnSpanFull(),
                                        Toggle::make('open_in_new_tab')->label('Открывать в новой вкладке'),
                                        Toggle::make('is_active')->label('Показывать сторис')->default(true),
                                    ])
                                    ->columns(2)
                                    ->defaultItems(0)
                                    ->addActionLabel('Добавить сторис')
                                    ->reorderable()
                                    ->collapsible()
                                    ->deleteAction(fn (Action $action): Action => $action
                                        ->requiresConfirmation()
                                        ->modalHeading('Удалить сторис из кружка?'))
                                    ->itemLabel(fn (array $state): string => (string) (($state['alt_text'] ?? null) ?: 'Сторис'))
                                    ->columnSpanFull(),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel('Добавить кружок')
                            ->reorderable()
                            ->collapsible()
                            ->deleteAction(fn (Action $action): Action => $action
                                ->requiresConfirmation()
                                ->modalHeading('Удалить кружок и все его сторис?'))
                            ->itemLabel(fn (array $state): string => (string) (($state['title'] ?? null) ?: 'Новый кружок'))
                            ->columnSpanFull(),
                    ]),
                Section::make('Быстрый поиск запчастей')
                    ->schema([
                        Hidden::make('search_section.id'),
                        TextInput::make('search_section.title')->label('Название секции')->maxLength(255),
                        Toggle::make('search_section.is_active')->label('Показывать секцию'),
                    ])->columns(2),
                Section::make('Витринные категории')
                    ->description('Изображение каждой карточки определяется её фиксированным кодом. Внешние ссылки не используются.')
                    ->schema([
                        Hidden::make('category_section.id'),
                        Toggle::make('category_section.is_active')->label('Показывать секцию'),
                        Repeater::make('category_cards')
                            ->label('Карточки категорий')
                            ->schema([
                                Hidden::make('id'),
                                Hidden::make('_label')->dehydrated(false),
                                TextInput::make('title')->label('Название')->required()->maxLength(255),
                                Select::make('destination_type')
                                    ->label('Назначение карточки')
                                    ->options(SitePageContentAdminService::categoryCardDestinationOptions())
                                    ->placeholder('Не выбрано')
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, ?string $state): void {
                                        if ($state !== 'product_category') {
                                            $set('product_category_id', null);
                                        }
                                        if ($state !== 'part_type') {
                                            $set('part_type_id', null);
                                        }
                                        if ($state === null || $state === '') {
                                            $set('is_active', false);
                                        }
                                    }),
                                Select::make('product_category_id')
                                    ->label('Категория магазина')
                                    ->options(fn (Get $get): array => app(SitePageContentAdminService::class)
                                        ->productCategoryOptions(is_numeric($get('product_category_id')) ? (int) $get('product_category_id') : null))
                                    ->searchable()
                                    ->preload()
                                    ->required(fn (Get $get): bool => $get('destination_type') === 'product_category')
                                    ->visible(fn (Get $get): bool => $get('destination_type') === 'product_category'),
                                Select::make('part_type_id')
                                    ->label('Тип детали')
                                    ->options(fn (Get $get): array => app(SitePageContentAdminService::class)
                                        ->partTypeOptions(is_numeric($get('part_type_id')) ? (int) $get('part_type_id') : null))
                                    ->searchable()
                                    ->preload()
                                    ->required(fn (Get $get): bool => $get('destination_type') === 'part_type')
                                    ->visible(fn (Get $get): bool => $get('destination_type') === 'part_type'),
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
                Section::make('Отзывы клиентов')
                    ->description('Review Lab подключён системно. Код виджета и внешний скрипт не редактируются.')
                    ->schema([
                        Hidden::make('reviews_section.id'),
                        TextInput::make('reviews_section.title')->label('Название секции')->maxLength(255),
                        Toggle::make('reviews_section.is_active')->label('Показывать секцию'),
                    ])->columns(2),
                Section::make('О компании')
                    ->schema([
                        Hidden::make('about_section.id'),
                        TextInput::make('about_section.title')->label('Название секции')->maxLength(255),
                        Toggle::make('about_section.is_active')->label('Показывать секцию'),
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
                    ])->columns(2),
            ]);
    }

    protected function loadState(SitePageContentAdminService $service): array
    {
        return $this->withStoryUploadFields($service->homepageState());
    }

    protected function persistState(SitePageContentAdminService $service, User $actor, array $data): void
    {
        $service->saveHomepage($actor, $this->withPersistedStoryMediaPath($data));
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function withStoryUploadFields(array $data): array
    {
        if (! is_array($data['stories'] ?? null)) {
            return $data;
        }

        foreach ($data['stories'] as &$group) {
            if (! is_array($group['items'] ?? null)) {
                continue;
            }

            foreach ($group['items'] as &$item) {
                $path = $item['media_path'] ?? null;
                $item['image_media_path'] = ($item['media_type'] ?? null) === HomepageStoryMediaType::Image->value ? $path : null;
                $item['video_media_path'] = ($item['media_type'] ?? null) === HomepageStoryMediaType::Video->value ? $path : null;
                unset($item['media_path']);
            }
            unset($item);
        }
        unset($group);

        return $data;
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function withPersistedStoryMediaPath(array $data): array
    {
        if (! is_array($data['stories'] ?? null)) {
            return $data;
        }

        foreach ($data['stories'] as &$group) {
            if (! is_array($group['items'] ?? null)) {
                continue;
            }

            foreach ($group['items'] as &$item) {
                $uploadField = ($item['media_type'] ?? null) === HomepageStoryMediaType::Video->value
                    ? 'video_media_path'
                    : 'image_media_path';
                $item['media_path'] = $item[$uploadField] ?? ($item['media_path'] ?? null);
                unset($item['image_media_path'], $item['video_media_path']);
            }
            unset($item);
        }
        unset($group);

        return $data;
    }

    protected function successNotificationTitle(): string
    {
        return 'Главная страница сохранена';
    }
}
