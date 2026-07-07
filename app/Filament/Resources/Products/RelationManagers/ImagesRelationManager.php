<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Media\ProductGalleryService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ImagesRelationManager extends RelationManager
{
    protected static string $relationship = 'images';

    protected static ?string $title = 'Изображения';

    protected static ?string $modelLabel = 'изображение';

    protected static ?string $pluralModelLabel = 'Изображения';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('path')
                ->label('Изображение')
                ->disk('public')
                ->directory(fn (?Model $record): string => 'uploads/products/'.($record?->product_id ?: $this->getOwnerRecord()->getKey()).'/manual')
                ->image()
                ->imageEditor()
                ->imagePreviewHeight('180')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->maxSize((int) ceil(config('media.max_source_size', 15 * 1024 * 1024) / 1024))
                ->required(fn (?ProductImage $record): bool => ! $record instanceof ProductImage || ! $record->is_default)
                ->disabled(fn (?ProductImage $record): bool => $record instanceof ProductImage && $record->is_default)
                ->columnSpanFull(),
            TextInput::make('alt')
                ->label('Alt')
                ->maxLength(255),
            Select::make('source_type')
                ->label('Источник')
                ->options([
                    ProductImage::SOURCE_DEFAULT => 'Дефолтное',
                    ProductImage::SOURCE_IMPORT => 'Импорт',
                    ProductImage::SOURCE_MANUAL => 'Ручное',
                ])
                ->default(ProductImage::SOURCE_MANUAL)
                ->disabled(fn (?ProductImage $record): bool => $record instanceof ProductImage && $record->is_default)
                ->required(),
            TextInput::make('position')
                ->label('Позиция')
                ->numeric()
                ->default(fn (): int => app(ProductGalleryService::class)->nextPosition($this->getOwnerRecord()))
                ->required(),
            Toggle::make('is_visible')
                ->label('Показывать')
                ->helperText('Главное изображение нельзя скрыть: сначала назначьте главным другое изображение.')
                ->default(true),
            Toggle::make('is_main')
                ->label('Главное')
                ->helperText('После сохранения у товара останется только одно главное изображение. Главное изображение всегда видимое.')
                ->default(fn (): bool => ! $this->getOwnerRecord()->images()->where('is_main', true)->where('is_visible', true)->exists()),
            Toggle::make('is_default')
                ->label('Дефолтное')
                ->disabled()
                ->dehydrated(false),
        ])->columns(3);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->reorderable('position')
            ->columns([
                ImageColumn::make('url')
                    ->label('Preview')
                    ->getStateUsing(fn (ProductImage $record): string => $record->url)
                    ->square(),
                TextColumn::make('source_type')
                    ->label('Источник')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => ProductImage::sourceTypeLabel($state))
                    ->color(fn (?string $state): string => ProductImage::sourceTypeColor($state)),
                ToggleColumn::make('is_visible')
                    ->label('Показ')
                    ->beforeStateUpdated(function (ProductImage $record, bool $state): void {
                        app(ProductGalleryService::class)->setVisible($record, $state);
                    }),
                ToggleColumn::make('is_main')
                    ->label('Главное')
                    ->beforeStateUpdated(function (ProductImage $record, bool $state): void {
                        if ($state) {
                            app(ProductGalleryService::class)->makeMain($record);
                        }
                    }),
                TextColumn::make('position')
                    ->label('Порядок')
                    ->sortable(),
                TextColumn::make('alt')
                    ->label('Alt')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('path')
                    ->label('Path')
                    ->limit(48)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Загрузить изображение')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->mutateFormDataUsing(function (array $data): array {
                        /** @var Product $product */
                        $product = $this->getOwnerRecord();

                        $data['product_id'] = $product->getKey();
                        $data['disk'] = 'public';
                        $data['source_type'] = ProductImage::SOURCE_MANUAL;
                        $data['is_default'] = false;
                        $data['is_visible'] = true;
                        $data['is_main'] = (bool) ($data['is_main'] ?? ! $product->images()->where('is_main', true)->where('is_visible', true)->exists());
                        $data['alt'] = $data['alt'] ?: $product->title;
                        $data['position'] = (int) ($data['position'] ?? app(ProductGalleryService::class)->nextPosition($product));

                        return $data;
                    }),
                Action::make('make_default_main')
                    ->label('Сделать дефолтное главным')
                    ->icon('heroicon-o-star')
                    ->requiresConfirmation()
                    ->action(fn (): ProductImage => app(ProductGalleryService::class)->makeDefaultMain($this->getOwnerRecord())),
                Action::make('reset_to_default')
                    ->label('Сбросить к дефолтной')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Сбросить галерею к дефолтному изображению?')
                    ->modalDescription('Будут удалены все ручные и импортные изображения товара вместе с файлами. Файл из public/img/products_default не удаляется.')
                    ->action(fn (): ProductImage => app(ProductGalleryService::class)->resetToDefault($this->getOwnerRecord())),
            ])
            ->recordActions([
                Action::make('open_image')
                    ->label('Открыть')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (ProductImage $record): string => $record->url)
                    ->openUrlInNewTab(),
                Action::make('download_image')
                    ->label('Скачать')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (ProductImage $record): string => $record->url)
                    ->openUrlInNewTab(),
                Action::make('make_main')
                    ->label('Главное')
                    ->icon('heroicon-o-star')
                    ->visible(fn (ProductImage $record): bool => ! $record->is_main)
                    ->action(fn (ProductImage $record): ProductImage => app(ProductGalleryService::class)->makeMain($record)),
                EditAction::make(),
                DeleteAction::make()
                    ->using(function (ProductImage $record): void {
                        app(ProductGalleryService::class)->deleteImage($record);
                    }),
            ]);
    }
}
