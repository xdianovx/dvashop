<?php

namespace App\Filament\Resources\Products\Actions;

use App\Models\Product;
use App\Services\Media\ProductGalleryService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Livewire\Component;
use RuntimeException;

final class ProductGalleryActions
{
    public static function makeDefaultMain(string $name = 'make_default_main'): Action
    {
        return Action::make($name)
            ->label('Сделать дефолтное главным')
            ->icon('heroicon-o-star')
            ->requiresConfirmation()
            ->modalHeading('Сделать дефолтное изображение главным?')
            ->modalDescription('Остальные изображения сохранятся в галерее.')
            ->action(function (Product $record, Component $livewire): void {
                $changed = self::run(
                    product: $record,
                    operation: fn (ProductGalleryService $gallery): mixed => $gallery->makeDefaultMain($record),
                    successTitle: 'Дефолтное изображение назначено главным',
                );

                self::refreshGalleryForm($livewire, $changed);
            });
    }

    public static function resetToDefault(string $name = 'reset_gallery_to_default'): Action
    {
        return Action::make($name)
            ->label('Сбросить галерею к дефолтной')
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Сбросить галерею к дефолтному изображению?')
            ->modalDescription('Ручные и импортные изображения будут удалены вместе с физическими файлами. Файл из public/img/products_default останется на месте.')
            ->action(function (Product $record, Component $livewire): void {
                $changed = self::run(
                    product: $record,
                    operation: fn (ProductGalleryService $gallery): mixed => $gallery->resetToDefault($record),
                    successTitle: 'Галерея сброшена к дефолтному изображению',
                );

                self::refreshGalleryForm($livewire, $changed);
            });
    }

    /** @param callable(ProductGalleryService): mixed $operation */
    private static function run(Product $product, callable $operation, string $successTitle): bool
    {
        try {
            $operation(app(ProductGalleryService::class));

            $product->unsetRelation('images');
            $product->unsetRelation('mainImage');

            Notification::make()
                ->success()
                ->title($successTitle)
                ->send();

            return true;
        } catch (RuntimeException $exception) {
            Notification::make()
                ->danger()
                ->title('Галерея не изменена')
                ->body($exception->getMessage())
                ->send();

            return false;
        }
    }

    private static function refreshGalleryForm(Component $livewire, bool $changed): void
    {
        if ($changed && method_exists($livewire, 'refreshProductGallery')) {
            $livewire->refreshProductGallery();
        }
    }
}
