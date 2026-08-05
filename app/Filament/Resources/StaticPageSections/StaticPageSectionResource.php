<?php

namespace App\Filament\Resources\StaticPageSections;

use App\Enums\StaticPageSectionCode;
use App\Filament\Resources\StaticPageSections\Pages\EditStaticPageSection;
use App\Filament\Resources\StaticPageSections\Pages\ListStaticPageSections;
use App\Filament\Resources\StaticPageSections\Pages\ViewStaticPageSection;
use App\Models\StaticPage;
use App\Models\StaticPageSection;
use App\Models\User;
use App\Services\StaticContent\StaticPageContentAdminService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StaticPageSectionResource extends Resource
{
    protected static ?string $model = StaticPageSection::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'display_title';

    /** @return array<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'label', 'code'];
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Контент сайта';
    }

    public static function getNavigationLabel(): string
    {
        return 'Блоки страниц';
    }

    public static function getModelLabel(): string
    {
        return 'блок страницы';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Блоки страниц';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('Системный код')
                ->formatStateUsing(fn (StaticPageSectionCode|string|null $state): string => $state instanceof StaticPageSectionCode ? $state->value : (string) $state)
                ->disabled()
                ->dehydrated(),
            Select::make('static_page_id')
                ->label('Страница')
                ->options(fn (): array => StaticPage::query()->ordered()->pluck('title', 'id')->all())
                ->disabled()
                ->dehydrated()
                ->required(),
            TextInput::make('label')->label('Подпись')->maxLength(255),
            TextInput::make('title')->label('Заголовок')->maxLength(255),
            Textarea::make('subtitle')->label('Подзаголовок')->rows(3)->columnSpanFull(),
            Textarea::make('body')->label('Текст блока')->rows(6)->columnSpanFull(),
            TextInput::make('position')->label('Позиция')->numeric()->integer()->minValue(0)->required(),
            Toggle::make('is_active')->label('Активен'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->columns([
                TextColumn::make('code')->label('Код')->formatStateUsing(fn (StaticPageSectionCode|string $state): string => $state instanceof StaticPageSectionCode ? $state->value : $state),
                TextColumn::make('page.title')->label('Страница')->sortable(),
                TextColumn::make('label')->label('Подпись')->placeholder('—')->searchable(),
                TextColumn::make('title')->label('Заголовок')->placeholder('—')->searchable(),
                IconColumn::make('is_active')->label('Активен')->boolean(),
                TextColumn::make('position')->label('Позиция')->numeric()->sortable(),
                TextColumn::make('updated_at')->label('Обновлён')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('static_page_id')->label('Страница')->options(fn (): array => StaticPage::query()->ordered()->pluck('title', 'id')->all()),
                TernaryFilter::make('is_active')->label('Активность')->trueLabel('Только активные')->falseLabel('Только неактивные'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('toggle_active')
                    ->label(fn (StaticPageSection $record): string => $record->is_active ? 'Деактивировать' : 'Активировать')
                    ->requiresConfirmation()
                    ->authorize(fn (StaticPageSection $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->action(fn (StaticPageSection $record): StaticPageSection => app(StaticPageContentAdminService::class)
                        ->setSectionActive(self::actor(), $record, ! $record->is_active)),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('page');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaticPageSections::route('/'),
            'view' => ViewStaticPageSection::route('/{record}'),
            'edit' => EditStaticPageSection::route('/{record}/edit'),
        ];
    }

    public static function actor(): User
    {
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
