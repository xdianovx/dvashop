<?php

namespace App\Filament\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;

final class SeoSchema
{
    public static function section(): Section
    {
        return Section::make('SEO')
            ->description('Метаданные для поисковых систем и превью в социальных сетях.')
            ->schema(self::fields())
            ->columns(2)
            ->collapsible()
            ->collapsed()
            ->columnSpanFull();
    }

    /** @return array<int, TextInput|Textarea|Toggle|FileUpload> */
    public static function fields(): array
    {
        return [
            TextInput::make('meta_title')
                ->label('Meta title')
                ->helperText('Рекомендуемо до 60–70 символов.')
                ->hint(fn (?string $state): string => self::lengthHint($state))
                ->live(debounce: 500)
                ->maxLength(255),
            TextInput::make('seo_h1')
                ->label('H1')
                ->helperText('Если пусто, на сайте можно использовать название сущности.')
                ->maxLength(255),
            Textarea::make('meta_description')
                ->label('Meta description')
                ->helperText('Рекомендуемо до 150–170 символов.')
                ->hint(fn (?string $state): string => self::lengthHint($state))
                ->live(debounce: 500)
                ->rows(3)
                ->maxLength(1000)
                ->columnSpanFull(),
            Textarea::make('seo_text')
                ->label('SEO-текст')
                ->rows(8)
                ->columnSpanFull(),
            TextInput::make('canonical_url')
                ->label('Canonical URL')
                ->url()
                ->maxLength(2048)
                ->helperText('Заполнять только если канонический адрес отличается от основного.')
                ->columnSpanFull(),
            Toggle::make('noindex')
                ->label('Не индексировать')
                ->helperText('Для служебных или временно скрытых страниц.')
                ->default(false),
            TextInput::make('og_title')
                ->label('Open Graph title')
                ->maxLength(255),
            Textarea::make('og_description')
                ->label('Open Graph description')
                ->rows(3)
                ->maxLength(1000)
                ->columnSpanFull(),
            FileUpload::make('og_image')
                ->label('Open Graph image')
                ->disk('public')
                ->directory('uploads/seo/open-graph')
                ->visibility('public')
                ->image()
                ->imagePreviewHeight('160')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(5 * 1024)
                ->downloadable()
                ->openable()
                ->columnSpanFull(),
        ];
    }

    private static function lengthHint(?string $state): string
    {
        return mb_strlen($state ?? '').' символов';
    }
}
