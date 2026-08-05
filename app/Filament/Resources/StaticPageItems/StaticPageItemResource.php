<?php

namespace App\Filament\Resources\StaticPageItems;

use App\Enums\StaticPageItemCode;
use App\Filament\Resources\StaticPageItems\Pages\EditStaticPageItem;
use App\Filament\Resources\StaticPageItems\Pages\ListStaticPageItems;
use App\Filament\Resources\StaticPageItems\Pages\ViewStaticPageItem;
use App\Models\StaticPage;
use App\Models\StaticPageItem;
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

class StaticPageItemResource extends Resource
{
    protected static ?string $model = StaticPageItem::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-list-bullet';

    protected static ?int $navigationSort = 30;

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
        return 'Элементы страниц';
    }

    public static function getModelLabel(): string
    {
        return 'элемент страницы';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Элементы страниц';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('Системный код')
                ->formatStateUsing(fn (StaticPageItemCode|string|null $state): string => $state instanceof StaticPageItemCode ? $state->value : (string) $state)
                ->disabled()
                ->dehydrated(),
            Select::make('static_page_section_id')
                ->label('Блок страницы')
                ->options(fn (): array => self::sectionOptions(includePage: true))
                ->disabled()
                ->dehydrated()
                ->required(),
            TextInput::make('label')->label('Подпись')->maxLength(255),
            TextInput::make('title')->label('Заголовок')->maxLength(500),
            Textarea::make('text')->label('Текст')->rows(6)->columnSpanFull(),
            TextInput::make('position')->label('Позиция')->numeric()->integer()->minValue(0)->required(),
            Toggle::make('is_active')->label('Активен'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->columns([
                TextColumn::make('code')->label('Код')->formatStateUsing(fn (StaticPageItemCode|string $state): string => $state instanceof StaticPageItemCode ? $state->value : $state),
                TextColumn::make('section.page.title')->label('Страница')->sortable(),
                TextColumn::make('section.display_title')->label('Блок')->placeholder('—'),
                TextColumn::make('label')->label('Подпись')->placeholder('—')->searchable(),
                TextColumn::make('title')->label('Заголовок')->placeholder('—')->searchable()->limit(60),
                IconColumn::make('is_active')->label('Активен')->boolean(),
                TextColumn::make('position')->label('Позиция')->numeric()->sortable(),
                TextColumn::make('updated_at')->label('Обновлён')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('page')
                    ->label('Страница')
                    ->options(fn (): array => StaticPage::query()->ordered()->pluck('title', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, mixed $pageId): Builder => $query->whereHas(
                            'section',
                            fn (Builder $sectionQuery): Builder => $sectionQuery->where('static_page_id', $pageId),
                        ),
                    )),
                SelectFilter::make('static_page_section_id')
                    ->label('Блок')
                    ->options(fn (): array => self::sectionOptions()),
                TernaryFilter::make('is_active')->label('Активность')->trueLabel('Только активные')->falseLabel('Только неактивные'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('toggle_active')
                    ->label(fn (StaticPageItem $record): string => $record->is_active ? 'Деактивировать' : 'Активировать')
                    ->requiresConfirmation()
                    ->authorize(fn (StaticPageItem $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->action(fn (StaticPageItem $record): StaticPageItem => app(StaticPageContentAdminService::class)
                        ->setItemActive(self::actor(), $record, ! $record->is_active)),
            ]);
    }

    /** @return array<int, string> */
    public static function sectionOptions(bool $includePage = false): array
    {
        return StaticPageSection::query()
            ->when($includePage, fn (Builder $query): Builder => $query->with('page'))
            ->ordered()
            ->get()
            ->mapWithKeys(fn (StaticPageSection $section): array => [
                $section->getKey() => $includePage
                    ? $section->page->title.' · '.$section->display_title
                    : $section->display_title,
            ])
            ->all();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('section.page');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaticPageItems::route('/'),
            'view' => ViewStaticPageItem::route('/{record}'),
            'edit' => EditStaticPageItem::route('/{record}/edit'),
        ];
    }

    public static function actor(): User
    {
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
