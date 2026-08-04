<?php

namespace App\Filament\Resources\ProductOptionGroups\RelationManagers;

use App\Models\ProductOptionGroup;
use App\Models\ProductOptionValue;
use App\Models\User;
use App\Services\Catalog\ProductOptionAdminService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ValuesRelationManager extends RelationManager
{
    protected static string $relationship = 'values';

    protected static ?string $title = 'Значения группы';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->label('Название')->required()->maxLength(255),
            TextInput::make('slug')
                ->label('Slug')
                ->required()
                ->maxLength(255)
                ->helperText('После использования значения slug и code изменить нельзя.'),
            TextInput::make('code')->label('Code')->maxLength(255)->nullable(),
            TextInput::make('position')
                ->label('Позиция')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->default(0)
                ->required(),
            Toggle::make('is_default')->label('По умолчанию')->default(false),
            Toggle::make('is_active')->label('Активно')->default(true),
            Textarea::make('description')->label('Описание')->rows(3)->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->defaultSort('position')
            ->reorderable('position', fn (): bool => auth()->user()?->can('reorder', ProductOptionValue::class) ?? false)
            ->authorizeReorder(fn (): bool => auth()->user()?->can('reorder', ProductOptionValue::class) ?? false)
            ->columns([
                TextColumn::make('title')->label('Название')->searchable()->sortable(),
                TextColumn::make('slug')->label('Slug')->searchable(),
                TextColumn::make('code')->label('Code')->searchable()->toggleable(),
                TextColumn::make('template_items_count')->label('В шаблонах')->numeric(),
                TextColumn::make('variant_option_values_count')->label('В вариантах')->numeric(),
                IconColumn::make('is_default')->label('По умолчанию')->boolean(),
                IconColumn::make('is_active')->label('Активно')->boolean(),
                TextColumn::make('position')->label('Позиция')->numeric()->sortable(),
                TextColumn::make('created_at')->label('Создано')->dateTime('d.m.Y H:i')->toggleable(),
                TextColumn::make('updated_at')->label('Обновлено')->dateTime('d.m.Y H:i')->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Активность')
                    ->trueLabel('Только активные')
                    ->falseLabel('Только неактивные'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->using(function (array $data): Model {
                        /** @var User $actor */
                        $actor = auth()->user();
                        /** @var ProductOptionGroup $group */
                        $group = $this->getOwnerRecord();

                        return app(ProductOptionAdminService::class)->createValue($actor, $group, $data);
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->using(function (ProductOptionValue $record, array $data): Model {
                        /** @var User $actor */
                        $actor = auth()->user();

                        return app(ProductOptionAdminService::class)->updateValue($actor, $record, $data);
                    }),
                Action::make('toggle_active')
                    ->label(fn (ProductOptionValue $record): string => $record->is_active ? 'Деактивировать' : 'Активировать')
                    ->requiresConfirmation()
                    ->modalDescription(fn (ProductOptionValue $record): string => sprintf(
                        'Связи не удаляются. Использование: шаблоны — %d, варианты — %d.',
                        $record->templateItems()->count(),
                        $record->variantOptionValues()->count(),
                    ))
                    ->authorize(fn (ProductOptionValue $record): bool => auth()->user()?->can('update', $record) ?? false)
                    ->action(function (ProductOptionValue $record): void {
                        /** @var User $actor */
                        $actor = auth()->user();
                        app(ProductOptionAdminService::class)->setValueActive($actor, $record, ! $record->is_active);
                    }),
            ])
            ->modifyQueryUsing(fn ($query) => $query->withCount(['templateItems', 'variantOptionValues']))
            ->emptyStateHeading('Значения группы не найдены');
    }
}
