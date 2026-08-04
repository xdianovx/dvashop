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
use Illuminate\Support\Facades\Gate;

class EditProduct extends EditRecord
{
    use HandlesProductGalleryUploads;
    use HandlesProductOptionValues;

    protected static string $resource = ProductResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->record->load([
            'fitments.generation' => fn ($query) => $query
                ->withTrashed()
                ->with([
                    'model' => fn ($modelQuery) => $modelQuery
                        ->withTrashed()
                        ->with([
                            'make' => fn ($makeQuery) => $makeQuery->withTrashed(),
                        ]),
                ]),
        ]);

        return $this->hydrateDefaultVariantData($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->prepareProductData($data);
    }

    protected function beforeSave(): void
    {
        $this->capturePersistedProductOptionSelections();
    }

    protected function afterSave(): void
    {
        $this->finishProductOptionSave();
        $this->finishProductSave();
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
                ->hidden(fn (): bool => Gate::denies('generateVariants', $this->record))
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
                    Gate::authorize('generateVariants', $this->record);

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
