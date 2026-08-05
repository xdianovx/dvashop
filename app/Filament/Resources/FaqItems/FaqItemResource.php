<?php

namespace App\Filament\Resources\FaqItems;

use App\Filament\Resources\FaqItems\Pages\CreateFaqItem;
use App\Filament\Resources\FaqItems\Pages\EditFaqItem;
use App\Filament\Resources\FaqItems\Pages\ListFaqItems;
use App\Filament\Resources\FaqItems\Pages\ViewFaqItem;
use App\Models\FaqCategory;
use App\Models\FaqItem;
use App\Models\User;
use App\Services\StaticContent\FaqAdminService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
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
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class FaqItemResource extends Resource
{
    protected static ?string $model = FaqItem::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'question';

    public static function getNavigationGroup(): ?string
    {
        return 'Контент сайта';
    }

    public static function getNavigationLabel(): string
    {
        return 'Вопросы FAQ';
    }

    public static function getModelLabel(): string
    {
        return 'вопрос FAQ';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Вопросы FAQ';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('Системный код')
                ->disabled()
                ->dehydrated(false)
                ->placeholder('Будет создан автоматически'),
            Select::make('faq_category_id')
                ->label('Категория')
                ->options(fn (): array => FaqCategory::query()->ordered()->pluck('title', 'id')->all())
                ->searchable()
                ->preload()
                ->required(),
            TextInput::make('question')->label('Вопрос')->required()->maxLength(500)->columnSpanFull(),
            Textarea::make('answer')->label('Ответ')->required()->rows(8)->maxLength(5000)->columnSpanFull(),
            TextInput::make('position')->label('Позиция')->numeric()->integer()->minValue(0)->default(0)->required(),
            Toggle::make('is_featured')->label('Рекомендуемый')->default(false),
            Toggle::make('is_active')->label('Активен')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->columns([
                TextColumn::make('code')->label('Код')->searchable()->toggleable(),
                TextColumn::make('category.title')->label('Категория')->sortable(),
                TextColumn::make('question')->label('Вопрос')->searchable()->limit(80),
                IconColumn::make('is_featured')->label('Рекомендуемый')->boolean(),
                IconColumn::make('is_active')->label('Активен')->boolean(),
                TextColumn::make('position')->label('Позиция')->numeric()->sortable(),
                TextColumn::make('updated_at')->label('Обновлён')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('faq_category_id')->label('Категория')->options(fn (): array => FaqCategory::query()->ordered()->pluck('title', 'id')->all()),
                TernaryFilter::make('is_active')->label('Активность')->trueLabel('Только активные')->falseLabel('Только неактивные'),
                TernaryFilter::make('is_featured')->label('Рекомендуемые')->trueLabel('Только рекомендуемые')->falseLabel('Только обычные'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('toggle_active')
                    ->label(fn (FaqItem $record): string => $record->is_active ? 'Деактивировать' : 'Активировать')
                    ->authorize(fn (FaqItem $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->action(fn (FaqItem $record): FaqItem => app(FaqAdminService::class)
                        ->setItemActive(self::actor(), $record, ! $record->is_active)),
                Action::make('toggle_featured')
                    ->label(fn (FaqItem $record): string => $record->is_featured ? 'Убрать из рекомендуемых' : 'Сделать рекомендуемым')
                    ->authorize(fn (FaqItem $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->action(fn (FaqItem $record): FaqItem => app(FaqAdminService::class)
                        ->setItemFeatured(self::actor(), $record, ! $record->is_featured)),
                DeleteAction::make()
                    ->successNotificationTitle('Вопрос FAQ удалён')
                    ->failureNotificationTitle('Не удалось удалить вопрос FAQ')
                    ->using(fn (FaqItem $record): bool => app(FaqAdminService::class)->deleteItem(self::actor(), $record)),
                RestoreAction::make()->using(fn (FaqItem $record): FaqItem => app(FaqAdminService::class)->restoreItem(self::actor(), $record)),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('category')
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFaqItems::route('/'),
            'create' => CreateFaqItem::route('/create'),
            'view' => ViewFaqItem::route('/{record}'),
            'edit' => EditFaqItem::route('/{record}/edit'),
        ];
    }

    public static function actor(): User
    {
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
