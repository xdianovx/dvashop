<?php

namespace App\Filament\Resources\StaticPages;

use App\Enums\StaticPageCode;
use App\Filament\Resources\StaticPages\Pages\EditStaticPage;
use App\Filament\Resources\StaticPages\Pages\ListStaticPages;
use App\Filament\Resources\StaticPages\Pages\ViewStaticPage;
use App\Models\StaticPage;
use App\Models\User;
use App\Services\StaticContent\StaticPageContentAdminService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class StaticPageResource extends Resource
{
    protected static ?string $model = StaticPage::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationGroup(): ?string
    {
        return 'Контент сайта';
    }

    public static function getNavigationLabel(): string
    {
        return 'Страницы';
    }

    public static function getModelLabel(): string
    {
        return 'страницу';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Страницы';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('Системный код')
                ->formatStateUsing(fn (StaticPageCode|string|null $state): string => $state instanceof StaticPageCode ? $state->value : (string) $state)
                ->disabled()
                ->dehydrated(),
            TextInput::make('title')->label('Заголовок')->required()->maxLength(255),
            Textarea::make('subtitle')->label('Подзаголовок')->rows(3)->columnSpanFull(),
            TextInput::make('primary_action_label')->label('Основная кнопка')->maxLength(255),
            TextInput::make('secondary_action_label')->label('Дополнительная кнопка')->maxLength(255),
            TextInput::make('position')->label('Позиция')->numeric()->integer()->minValue(0)->required(),
            Toggle::make('is_active')->label('Активна'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->reorderable('position')
            ->authorizeReorder(fn (): bool => auth()->user()?->can('reorder', StaticPage::class) ?? false)
            ->columns([
                TextColumn::make('code')->label('Код')->formatStateUsing(fn (StaticPageCode|string $state): string => $state instanceof StaticPageCode ? $state->value : $state),
                TextColumn::make('title')->label('Заголовок')->searchable(),
                IconColumn::make('is_active')->label('Активна')->boolean(),
                TextColumn::make('position')->label('Позиция')->numeric()->sortable(),
                TextColumn::make('updated_at')->label('Обновлена')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Активность')->trueLabel('Только активные')->falseLabel('Только неактивные'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('toggle_active')
                    ->label(fn (StaticPage $record): string => $record->is_active ? 'Деактивировать' : 'Активировать')
                    ->requiresConfirmation()
                    ->authorize(fn (StaticPage $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->action(fn (StaticPage $record): StaticPage => app(StaticPageContentAdminService::class)
                        ->setPageActive(self::actor(), $record, ! $record->is_active)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStaticPages::route('/'),
            'view' => ViewStaticPage::route('/{record}'),
            'edit' => EditStaticPage::route('/{record}/edit'),
        ];
    }

    public static function actor(): User
    {
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
