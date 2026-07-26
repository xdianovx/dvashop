<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\Pages\Concerns\HandlesProductGalleryUploads;
use App\Filament\Resources\Products\Pages\Concerns\HandlesProductOptionValues;
use App\Filament\Resources\Products\ProductResource;
use App\Services\Catalog\ProductVariantOptionGenerator;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    use HandlesProductGalleryUploads;
    use HandlesProductOptionValues;

    protected static string $resource = ProductResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->hydrateDefaultVariantData($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->prepareProductData($data);
    }

    protected function afterSave(): void
    {
        $this->finishProductSave();
        $this->finishProductOptionSave();
    }

    public function refreshProductGallery(): void
    {
        $this->record->refresh();
        $this->fillForm();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate_variants_from_template')
                ->label('Создать варианты по шаблону')
                ->icon('heroicon-o-squares-plus')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Создать недостающие варианты?')
                ->modalDescription(function (): string {
                    $template = $this->record->optionTemplate;
                    $count = $template
                        ? count(app(ProductVariantOptionGenerator::class)->combinationsForTemplate($template))
                        : 0;

                    return "Будет проверено комбинаций: {$count}. За один запуск обрабатывается не более 100 комбинаций; существующие варианты не дублируются.";
                })
                ->action(function (): void {
                    $created = app(ProductVariantOptionGenerator::class)->createMissingVariants($this->record);

                    $this->record->refresh();
                    $this->fillForm();

                    Notification::make()
                        ->success()
                        ->title("Создано {$created} вариантов.")
                        ->send();
                }),
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }
}
