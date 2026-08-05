<?php

namespace App\Filament\Resources\HomepageMetrics;

use App\Enums\HomepageMetricCode;
use App\Filament\Resources\HomepageMetrics\Pages\EditHomepageMetric;
use App\Filament\Resources\HomepageMetrics\Pages\ListHomepageMetrics;
use App\Filament\Resources\HomepageMetrics\Pages\ViewHomepageMetric;
use App\Models\HomepageMetric;
use App\Models\User;
use App\Services\Homepage\HomepageContentAdminService;
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

class HomepageMetricResource extends Resource
{
    protected static ?string $model = HomepageMetric::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'text';

    public static function getNavigationGroup(): ?string
    {
        return 'Главная страница';
    }

    public static function getNavigationLabel(): string
    {
        return 'Показатели';
    }

    public static function getModelLabel(): string
    {
        return 'показатель';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Показатели';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('Системный код')
                ->formatStateUsing(fn (HomepageMetricCode|string|null $state): string => $state instanceof HomepageMetricCode ? $state->value : (string) $state)
                ->disabled()
                ->dehydrated(),
            TextInput::make('prefix')->label('Префикс')->maxLength(32),
            TextInput::make('value')->label('Значение')->required()->maxLength(64),
            TextInput::make('suffix')->label('Суффикс')->maxLength(64),
            Textarea::make('text')->label('Описание')->required()->maxLength(500)->rows(3)->columnSpanFull(),
            TextInput::make('position')->label('Позиция')->numeric()->integer()->minValue(0)->required(),
            Toggle::make('is_active')->label('Активен'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->reorderable('position')
            ->authorizeReorder(fn (): bool => auth()->user()?->can('reorder', HomepageMetric::class) ?? false)
            ->columns([
                TextColumn::make('code')->label('Код')->formatStateUsing(fn (HomepageMetricCode|string $state): string => $state instanceof HomepageMetricCode ? $state->value : $state),
                TextColumn::make('prefix')->label('Префикс')->placeholder('—'),
                TextColumn::make('value')->label('Значение'),
                TextColumn::make('suffix')->label('Суффикс')->placeholder('—'),
                TextColumn::make('text')->label('Описание')->wrap()->limit(80),
                IconColumn::make('is_active')->label('Активен')->boolean(),
                TextColumn::make('position')->label('Позиция')->numeric()->sortable(),
                TextColumn::make('updated_at')->label('Обновлён')->dateTime('d.m.Y H:i')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Активность')->trueLabel('Только активные')->falseLabel('Только неактивные'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('toggle_active')
                    ->label(fn (HomepageMetric $record): string => $record->is_active ? 'Деактивировать' : 'Активировать')
                    ->requiresConfirmation()
                    ->authorize(fn (HomepageMetric $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->action(fn (HomepageMetric $record): HomepageMetric => app(HomepageContentAdminService::class)
                        ->setMetricActive(self::actor(), $record, ! $record->is_active)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHomepageMetrics::route('/'),
            'view' => ViewHomepageMetric::route('/{record}'),
            'edit' => EditHomepageMetric::route('/{record}/edit'),
        ];
    }

    public static function actor(): User
    {
        $actor = Filament::auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
