<?php

namespace App\Filament\Resources\HomepageSections;

use App\Enums\HomepageSectionCode;
use App\Filament\Resources\HomepageSections\Pages\EditHomepageSection;
use App\Filament\Resources\HomepageSections\Pages\ListHomepageSections;
use App\Filament\Resources\HomepageSections\Pages\ViewHomepageSection;
use App\Models\HomepageSection;
use App\Models\User;
use App\Services\Homepage\HomepageContentAdminService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class HomepageSectionResource extends Resource
{
    protected static ?string $model = HomepageSection::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationGroup(): ?string
    {
        return 'Главная страница';
    }

    public static function getNavigationLabel(): string
    {
        return 'Секции';
    }

    public static function getModelLabel(): string
    {
        return 'секцию';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Секции';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('Системный код')
                ->formatStateUsing(fn (HomepageSectionCode|string|null $state): string => $state instanceof HomepageSectionCode ? $state->value : (string) $state)
                ->disabled()
                ->dehydrated(),
            TextInput::make('title')->label('Заголовок')->maxLength(255),
            TextInput::make('position')->label('Позиция')->numeric()->integer()->minValue(0)->required(),
            Toggle::make('is_active')->label('Активна'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->reorderable('position')
            ->authorizeReorder(fn (): bool => auth()->user()?->can('reorder', HomepageSection::class) ?? false)
            ->columns([
                TextColumn::make('code')->label('Код')->formatStateUsing(fn (HomepageSectionCode|string $state): string => $state instanceof HomepageSectionCode ? $state->value : $state),
                TextColumn::make('title')->label('Заголовок')->placeholder('—')->searchable(),
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
                    ->label(fn (HomepageSection $record): string => $record->is_active ? 'Деактивировать' : 'Активировать')
                    ->requiresConfirmation()
                    ->authorize(fn (HomepageSection $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->action(fn (HomepageSection $record): HomepageSection => app(HomepageContentAdminService::class)
                        ->setSectionActive(self::actor(), $record, ! $record->is_active)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHomepageSections::route('/'),
            'view' => ViewHomepageSection::route('/{record}'),
            'edit' => EditHomepageSection::route('/{record}/edit'),
        ];
    }

    public static function actor(): User
    {
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
