<?php

namespace App\Filament\Resources\FaqCategories;

use App\Filament\Resources\FaqCategories\Pages\CreateFaqCategory;
use App\Filament\Resources\FaqCategories\Pages\EditFaqCategory;
use App\Filament\Resources\FaqCategories\Pages\ListFaqCategories;
use App\Filament\Resources\FaqCategories\Pages\ViewFaqCategory;
use App\Models\FaqCategory;
use App\Models\User;
use App\Services\StaticContent\FaqAdminService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class FaqCategoryResource extends Resource
{
    protected static ?string $model = FaqCategory::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-folder-open';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationGroup(): ?string
    {
        return 'Контент сайта';
    }

    public static function getNavigationLabel(): string
    {
        return 'Категории FAQ';
    }

    public static function getModelLabel(): string
    {
        return 'категорию FAQ';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Категории FAQ';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('Системный код')
                ->disabled()
                ->dehydrated(false)
                ->placeholder('Будет создан автоматически'),
            TextInput::make('title')->label('Название')->required()->maxLength(255),
            TextInput::make('position')->label('Позиция')->numeric()->integer()->minValue(0)->default(0)->required(),
            Toggle::make('is_active')->label('Активна')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->reorderable('position')
            ->authorizeReorder(fn (): bool => auth()->user()?->can('reorder', FaqCategory::class) ?? false)
            ->columns([
                TextColumn::make('code')->label('Код')->searchable(),
                TextColumn::make('title')->label('Название')->searchable(),
                TextColumn::make('items_count')->label('Вопросов')->numeric(),
                IconColumn::make('is_active')->label('Активна')->boolean(),
                TextColumn::make('position')->label('Позиция')->numeric()->sortable(),
                TextColumn::make('updated_at')->label('Обновлена')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Активность')->trueLabel('Только активные')->falseLabel('Только неактивные'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('toggle_active')
                    ->label(fn (FaqCategory $record): string => $record->is_active ? 'Деактивировать' : 'Активировать')
                    ->authorize(fn (FaqCategory $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->action(fn (FaqCategory $record): FaqCategory => app(FaqAdminService::class)
                        ->setCategoryActive(self::actor(), $record, ! $record->is_active)),
                DeleteAction::make()
                    ->successNotificationTitle('Категория FAQ удалена')
                    ->failureNotificationTitle('Не удалось удалить категорию FAQ')
                    ->using(fn (FaqCategory $record): bool => app(FaqAdminService::class)->deleteCategory(self::actor(), $record)),
                RestoreAction::make()->using(fn (FaqCategory $record): FaqCategory => app(FaqAdminService::class)->restoreCategory(self::actor(), $record)),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount('items')
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFaqCategories::route('/'),
            'create' => CreateFaqCategory::route('/create'),
            'view' => ViewFaqCategory::route('/{record}'),
            'edit' => EditFaqCategory::route('/{record}/edit'),
        ];
    }

    public static function actor(): User
    {
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
