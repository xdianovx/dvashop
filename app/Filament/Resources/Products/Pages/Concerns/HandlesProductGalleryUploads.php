<?php

namespace App\Filament\Resources\Products\Pages\Concerns;

use App\Enums\ProductType;
use App\Models\Product;
use App\Models\ProductOptionTemplate;
use App\Models\ProductVariant;
use App\Services\Catalog\CatalogRelationIdNormalizer;
use App\Services\Catalog\ProductAdminService;
use App\Services\Catalog\ProductOptionTemplateResolver;
use App\Services\Catalog\ProductVariantAdminService;
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

        $relationIds = app(CatalogRelationIdNormalizer::class);
        $data['part_type_id'] = $relationIds->nullablePositive(
            array_key_exists('part_type_id', $rawState)
                ? $rawState['part_type_id']
                : ($data['part_type_id'] ?? null),
            'data.part_type_id',
        );
        $data['product_option_template_id'] = $relationIds->nullablePositive(
            array_key_exists('product_option_template_id', $rawState)
                ? $rawState['product_option_template_id']
                : ($data['product_option_template_id'] ?? null),
            'data.product_option_template_id',
        );

        if ($this->isGenericProductType($data['product_type'] ?? null)) {
            if ($record instanceof Product && $record->exists && ! $record->isGeneric()) {
                app(ProductAdminService::class)->clearAutoPartRelationsForGenericTransition($record);
            }

            $data['part_type_id'] = null;
            $data['product_option_template_id'] = null;
            unset($data['fitments']);
        } else {
            $this->validateAutoPartOptionTemplate(
                $data['product_option_template_id'],
                $data['part_type_id'],
                $record instanceof Product ? $record : null,
            );
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

        app(ProductVariantAdminService::class)->save($variant, [
            'product_id' => $product->getKey(),
            'is_default' => true,
        ]);

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

    private function validateAutoPartOptionTemplate(
        ?int $templateId,
        ?int $partTypeId,
        ?Product $record,
    ): void {
        if ($templateId === null) {
            return;
        }

        $template = ProductOptionTemplate::query()->find($templateId);

        if (! $template instanceof ProductOptionTemplate) {
            throw ValidationException::withMessages([
                'data.product_option_template_id' => 'Выбранный шаблон опций не существует.',
            ]);
        }

        if (! app(ProductOptionTemplateResolver::class)->isCompatible(
            $template,
            ProductType::AutoPart,
            $partTypeId,
        )) {
            throw ValidationException::withMessages([
                'data.product_option_template_id' => 'Шаблон опций не подходит для выбранного типа детали. Выберите совместимый шаблон или очистите поле.',
            ]);
        }

        $isPersistedSelection = $record?->exists === true
            && app(CatalogRelationIdNormalizer::class)->nullablePositive(
                $record->product_option_template_id,
                'data.product_option_template_id',
            ) === $templateId;

        if (! $template->is_active && ! $isPersistedSelection) {
            throw ValidationException::withMessages([
                'data.product_option_template_id' => 'Нельзя назначить товару неактивный шаблон опций.',
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
