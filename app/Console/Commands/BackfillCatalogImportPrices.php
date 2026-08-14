<?php

namespace App\Console\Commands;

use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Import\CatalogImportPriceResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillCatalogImportPrices extends Command
{
    protected $signature = 'catalog:backfill-import-prices
        {--apply : Записать справочные цены; без флага выполняется только dry-run}';

    protected $description = 'Заполнить отсутствующие цены catalog-import товаров из локального PartType reference mapping';

    public function handle(CatalogImportPriceResolver $resolver): int
    {
        $apply = (bool) $this->option('apply');
        $counters = $this->emptyCounters();
        $groups = [];

        $this->components->info('Catalog import reference prices');
        $this->line('mode='.($apply ? 'APPLY' : 'DRY-RUN'));

        Product::query()
            ->where('import_source', 'catalog')
            ->whereNotNull('import_key')
            ->select(['id', 'part_type_id', 'price', 'old_price'])
            ->with([
                'partType:id,full_slug',
                'defaultVariant:id,product_id,price,old_price,is_default',
            ])
            ->orderBy('id')
            ->chunkById(200, function ($products) use ($resolver, $apply, &$counters, &$groups): void {
                foreach ($products as $product) {
                    $counters['catalog_products_scanned']++;

                    $partType = $product->partType;
                    $reference = $partType instanceof PartType ? $resolver->resolve($partType) : null;

                    if ($reference === null) {
                        $counters['unmapped_products']++;

                        continue;
                    }

                    $counters['mapped_products']++;
                    $slug = (string) $partType->full_slug;
                    $groups[$slug] ??= [
                        'full_slug' => $slug,
                        'price' => $reference['price'],
                        'old_price' => $reference['old_price'],
                        'products_count' => 0,
                        'variants_to_update' => 0,
                        'already_positive_count' => 0,
                    ];
                    $groups[$slug]['products_count']++;

                    $productPriceNeedsUpdate = ! $this->isPositive($product->price);
                    if ($productPriceNeedsUpdate) {
                        $counters['product_prices_to_update']++;
                    } else {
                        $counters['positive_product_prices_preserved']++;
                    }

                    $productOldPriceNeedsUpdate = $reference['old_price'] !== null
                        && ! $this->isPositive($product->old_price);
                    if ($productOldPriceNeedsUpdate) {
                        $counters['old_prices_to_update']++;
                    }

                    $variant = $product->defaultVariant;
                    if (! $variant instanceof ProductVariant) {
                        $counters['missing_default_variants']++;

                        if ($apply && ($productPriceNeedsUpdate || $productOldPriceNeedsUpdate)) {
                            $this->applyReferencePrices(
                                productId: (int) $product->getKey(),
                                partTypeId: (int) $partType->getKey(),
                                reference: $reference,
                            );
                        }

                        continue;
                    }

                    $variantPriceNeedsUpdate = ! $this->isPositive($variant->price);
                    if ($variantPriceNeedsUpdate) {
                        $counters['variant_prices_to_update']++;
                        $groups[$slug]['variants_to_update']++;
                    } else {
                        $counters['positive_variant_prices_preserved']++;
                        $groups[$slug]['already_positive_count']++;
                    }

                    $variantOldPriceNeedsUpdate = $reference['old_price'] !== null
                        && ! $this->isPositive($variant->old_price);
                    if ($variantOldPriceNeedsUpdate) {
                        $counters['old_prices_to_update']++;
                    }

                    if ($apply && ($productPriceNeedsUpdate
                        || $productOldPriceNeedsUpdate
                        || $variantPriceNeedsUpdate
                        || $variantOldPriceNeedsUpdate)) {
                        $this->applyReferencePrices(
                            productId: (int) $product->getKey(),
                            partTypeId: (int) $partType->getKey(),
                            reference: $reference,
                        );
                    }
                }
            });

        $this->renderSummary($counters);
        $this->renderGroups($groups);

        if ($apply) {
            $this->components->info('Reference price backfill завершён. Положительные цены не перезаписывались.');
        } else {
            $this->components->info('Dry-run завершён. База данных не изменялась. Для применения используйте --apply.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{price: string, old_price: string|null}  $reference
     */
    private function applyReferencePrices(int $productId, int $partTypeId, array $reference): void
    {
        DB::transaction(function () use ($productId, $partTypeId, $reference): void {
            $product = Product::query()
                ->whereKey($productId)
                ->where('import_source', 'catalog')
                ->whereNotNull('import_key')
                ->where('part_type_id', $partTypeId)
                ->lockForUpdate()
                ->first();

            if (! $product instanceof Product) {
                return;
            }

            $productUpdates = [];
            if (! $this->isPositive($product->price)) {
                $productUpdates['price'] = $reference['price'];
            }
            if ($reference['old_price'] !== null && ! $this->isPositive($product->old_price)) {
                $productUpdates['old_price'] = $reference['old_price'];
            }
            if ($productUpdates !== []) {
                Product::query()->whereKey($productId)->update($productUpdates);
            }

            $variant = ProductVariant::query()
                ->where('product_id', $productId)
                ->where('is_default', true)
                ->lockForUpdate()
                ->first();

            if (! $variant instanceof ProductVariant) {
                return;
            }

            $variantUpdates = [];
            if (! $this->isPositive($variant->price)) {
                $variantUpdates['price'] = $reference['price'];
            }
            if ($reference['old_price'] !== null && ! $this->isPositive($variant->old_price)) {
                $variantUpdates['old_price'] = $reference['old_price'];
            }
            if ($variantUpdates !== []) {
                ProductVariant::query()->whereKey($variant->getKey())->update($variantUpdates);
            }
        });
    }

    /** @return array<string, int> */
    private function emptyCounters(): array
    {
        return [
            'catalog_products_scanned' => 0,
            'mapped_products' => 0,
            'unmapped_products' => 0,
            'product_prices_to_update' => 0,
            'variant_prices_to_update' => 0,
            'old_prices_to_update' => 0,
            'positive_product_prices_preserved' => 0,
            'positive_variant_prices_preserved' => 0,
            'missing_default_variants' => 0,
        ];
    }

    /** @param array<string, int> $counters */
    private function renderSummary(array $counters): void
    {
        $this->newLine();
        $this->line('Summary');

        foreach ($counters as $name => $value) {
            $this->line($name.'='.$value);
        }
    }

    /**
     * @param  array<string, array{full_slug: string, price: string, old_price: string|null, products_count: int, variants_to_update: int, already_positive_count: int}>  $groups
     */
    private function renderGroups(array $groups): void
    {
        if ($groups === []) {
            return;
        }

        ksort($groups);
        $this->newLine();
        $this->table(
            ['PartType full_slug', 'reference price', 'reference old_price', 'products count', 'variants to update', 'already positive count'],
            array_map(static fn (array $group): array => [
                $group['full_slug'],
                $group['price'],
                $group['old_price'] ?? '—',
                $group['products_count'],
                $group['variants_to_update'],
                $group['already_positive_count'],
            ], array_values($groups)),
        );
    }

    private function isPositive(mixed $value): bool
    {
        return $value !== null && (float) $value > 0;
    }
}
