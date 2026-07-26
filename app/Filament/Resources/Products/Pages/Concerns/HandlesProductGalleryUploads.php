<?php

namespace App\Filament\Resources\Products\Pages\Concerns;

use App\Enums\ProductType;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use App\Models\ProductVariant;
use App\Services\Media\ProductGalleryService;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

trait HandlesProductGalleryUploads
{
    /** @var array<int, string> */
    private array $pendingGalleryUploads = [];

    /** @var array{price: mixed, old_price: mixed, stock_quantity: mixed, stock_status: mixed} */
    private array $defaultVariantData = [];

    private mixed $technicalVariantSku = null;

    private bool $usesExplicitVariantData = false;

    private bool $keepProductWithoutVariants = false;

    /** @param array<string, mixed> $data @return array<string, mixed> */
    protected function hydrateDefaultVariantData(array $data): array
    {
        /** @var Product $product */
        $product = $this->record;
        $variant = $product->defaultVariant()->first();

        if (! $variant instanceof ProductVariant) {
            $data['default_stock_quantity'] = null;
            $data['variant_management_mode'] = null;

            return $data;
        }

        $data['variant_management_mode'] = $variant->managementMode();
        $data['price'] = $variant->price;
        $data['old_price'] = $variant->old_price;
        $data['default_stock_quantity'] = $variant->stock_quantity;
        $data['stock_status'] = $variant->stock_status;

        return $data;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    protected function prepareProductData(array $data): array
    {
        $uploads = $data['gallery_uploads'] ?? [];
        unset($data['gallery_uploads']);

        $this->pendingGalleryUploads = $this->validatedPendingGalleryUploads($uploads);

        $record = $this->record ?? null;
        $isCreating = ! $record instanceof Product;
        $rawState = $this->form->getRawState();
        $rawVariants = $rawState['variants'] ?? ($data['variants'] ?? []);
        $variants = array_values(array_filter(
            is_array($rawVariants) ? $rawVariants : [],
            'is_array',
        ));
        $existingVariants = $record instanceof Product && $record->exists
            ? $record->variants()->get()
            : collect();
        $existingVariantCount = $existingVariants->count();
        $hasSingleTechnicalVariant = $existingVariantCount === 1
            && ($rawState['variant_management_mode'] ?? null) === ProductVariant::MANAGEMENT_TECHNICAL;

        $this->usesExplicitVariantData = $isCreating
            ? $variants !== []
            : ($existingVariantCount > 0 ? ! $hasSingleTechnicalVariant : $variants !== []);
        $this->keepProductWithoutVariants = $record instanceof Product
            && $record->exists
            && $existingVariantCount === 0
            && ! filled($data['default_stock_quantity'] ?? null)
            && $variants === [];

        $this->technicalVariantSku = $data['sku'] ?? null;
        $this->defaultVariantData = [
            'price' => $data['price'] ?? 0,
            'old_price' => $data['old_price'] ?? null,
            'stock_quantity' => $data['default_stock_quantity'] ?? null,
            'stock_status' => $data['stock_status'] ?? null,
        ];
        unset($data['default_stock_quantity']);

        if ($this->isGenericProductType($data['product_type'] ?? null)) {
            $data['part_type_id'] = null;
            $data['product_option_template_id'] = null;
            unset($data['fitments']);
        } else {
            $this->validateAutoPartOptionTemplate($data['product_option_template_id'] ?? null);
        }

        return $data;
    }

    protected function finishProductSave(): void
    {
        $this->clearGenericProductRelations();
        $this->syncDefaultVariant();
        $this->persistPendingGalleryUploads();
    }

    private function syncDefaultVariant(): void
    {
        /** @var Product $product */
        $product = $this->record->refresh();

        /** @var ProductVariant|null $variant */
        $variant = $product->variants()->where('is_default', true)->first()
            ?? $product->variants()->orderBy('id')->first();

        if (! $variant instanceof ProductVariant) {
            if ($this->keepProductWithoutVariants) {
                return;
            }

            $variant = new ProductVariant([
                ...$this->defaultVariantData,
                'sku' => $this->technicalVariantSku,
                'product_id' => $product->getKey(),
                'title' => 'Основной',
                'options' => ProductVariant::technicalOptions(),
                'is_active' => true,
            ]);
        } elseif (! $this->usesExplicitVariantData) {
            $variant->forceFill([
                ...$this->defaultVariantData,
                'options' => ProductVariant::technicalOptions(),
                'is_active' => true,
            ]);
        }

        $variant->forceFill([
            'product_id' => $product->getKey(),
            'is_default' => true,
        ])->save();

        $product->forceFill([
            'price' => $variant->price,
            'old_price' => $variant->old_price,
            'stock_status' => $variant->stock_status,
        ])->saveQuietly();

        $product->unsetRelation('defaultVariant');
        $product->unsetRelation('variants');
    }

    protected function persistPendingGalleryUploads(): void
    {
        if ($this->pendingGalleryUploads === []) {
            return;
        }

        /** @var Product $product */
        $product = $this->record;

        $images = app(ProductGalleryService::class)->attachManualImages(
            product: $product,
            paths: $this->pendingGalleryUploads,
            alt: $product->title,
        );

        $this->pendingGalleryUploads = [];

        Notification::make()
            ->success()
            ->title('Изображения загружены')
            ->body('Добавлено изображений: '.$images->count())
            ->send();
    }

    private function clearGenericProductRelations(): void
    {
        /** @var Product $product */
        $product = $this->record->refresh();

        if (! $product->isGeneric()) {
            return;
        }

        if ($product->part_type_id !== null) {
            $product->forceFill(['part_type_id' => null])->saveQuietly();
        }

        $product->fitments()->delete();
        $product->unsetRelation('fitments');
    }

    private function isGenericProductType(mixed $state): bool
    {
        return ($state instanceof ProductType ? $state->value : $state) === ProductType::Generic->value;
    }

    private function validateAutoPartOptionTemplate(mixed $templateId): void
    {
        if (! filled($templateId)) {
            return;
        }

        $isCompatible = ProductOptionTemplate::query()
            ->whereKey($templateId)
            ->whereIn('applies_to', [
                ProductOptionGroup::APPLIES_ALL,
                ProductOptionGroup::APPLIES_AUTO_PART,
            ])
            ->exists();

        if (! $isCompatible) {
            throw ValidationException::withMessages([
                'data.product_option_template_id' => 'Шаблон опций не подходит для типа товара «Автодеталь».',
            ]);
        }
    }

    /** @return array<int, string> */
    private function validatedPendingGalleryUploads(mixed $uploads): array
    {
        $paths = array_values(array_filter(
            is_array($uploads) ? $uploads : [$uploads],
            static fn (mixed $path): bool => is_string($path) && trim($path) !== '',
        ));

        foreach ($paths as $path) {
            $path = trim($path);
            $isSafePendingPath = str_starts_with($path, 'uploads/products/pending/manual/')
                && ! str_contains($path, '..')
                && ! filter_var($path, FILTER_VALIDATE_URL)
                && Storage::disk('public')->exists($path);

            if (! $isSafePendingPath) {
                throw ValidationException::withMessages([
                    'data.gallery_uploads' => 'Загруженный файл недоступен или имеет недопустимый путь. Выберите изображение повторно.',
                ]);
            }
        }

        return array_values(array_unique(array_map('trim', $paths)));
    }
}
