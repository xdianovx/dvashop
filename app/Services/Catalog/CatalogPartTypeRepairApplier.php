<?php

namespace App\Services\Catalog;

use App\Enums\ProductType;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class CatalogPartTypeRepairApplier
{
    public function __construct(
        private readonly CatalogPartTypeRepairInspector $inspector,
        private readonly ProductCategoryCatalogRegistry $categoryRegistry,
        private readonly PartTypeDefinitionRegistry $partTypeRegistry,
        private readonly PartTypeTreeService $tree,
        private readonly PartTypeCategoryResolver $resolver,
    ) {}

    public function apply(CatalogPartTypeRepairPlan $plan): CatalogPartTypeRepairResult
    {
        if ($plan->hasBlockers()) {
            throw new RuntimeException('Repair не запущен: preflight содержит блокирующие конфликты.');
        }

        return DB::transaction(function (): CatalogPartTypeRepairResult {
            $freshPlan = $this->inspector->inspect(lock: true);

            if ($freshPlan->hasBlockers()) {
                throw new RuntimeException('Repair отменён: данные изменились, повторный preflight обнаружил блокирующие конфликты.');
            }

            $counters = CatalogPartTypeRepairResult::emptyCounters();
            $warnings = $freshPlan->warnings;
            $this->repairStoreStructure($freshPlan, $counters);
            $this->resolver->resetLocalCache();

            $partTypes = $this->ensureKnownPartTypes($counters);

            foreach ($freshPlan->unknownChildren as $entry) {
                if (! isset($partTypes[$entry['part_type_path']])) {
                    $partTypes[$entry['part_type_path']] = $this->ensureUnknownPartType($entry, $partTypes, $counters);
                }

                $counters['fallback_used']++;
            }

            foreach ($freshPlan->technicalCategories as $entry) {
                $partType = $partTypes[$entry['part_type_path']] ?? PartType::withTrashed()
                    ->lockForUpdate()
                    ->where('full_slug', $entry['part_type_path'])
                    ->first();

                if (! $partType instanceof PartType) {
                    throw new RuntimeException("PartType «{$entry['part_type_path']}» не найден во время применения repair.");
                }

                $category = ProductCategory::withTrashed()
                    ->lockForUpdate()
                    ->where('full_slug', $entry['store_category_path'])
                    ->first();

                if (! $category instanceof ProductCategory || $category->trashed()) {
                    throw new RuntimeException("Категория магазина «{$entry['store_category_path']}» недоступна во время применения repair.");
                }

                $this->repairProductsForTechnicalCategory($entry, $partType, $category, $counters);
            }

            $deactivationEntries = $freshPlan->technicalCategories;
            usort(
                $deactivationEntries,
                static fn (array $left, array $right): int => count($left['descendant_ids'])
                    <=> count($right['descendant_ids']),
            );
            $keptActiveCategoryIds = [];

            foreach ($deactivationEntries as $entry) {
                $legacyCategory = ProductCategory::withTrashed()->lockForUpdate()->find($entry['category_id']);

                if (! $legacyCategory instanceof ProductCategory) {
                    throw new RuntimeException("Legacy-категория #{$entry['category_id']} исчезла во время repair.");
                }

                if (! $entry['can_deactivate']) {
                    if ($this->keepLegacyCategoryActive($legacyCategory, $counters)) {
                        $keptActiveCategoryIds[$entry['category_id']] = true;
                    }

                    continue;
                }

                $activeDescendantIds = array_values(array_filter(
                    $entry['descendant_ids'],
                    static fn (int $categoryId): bool => isset($keptActiveCategoryIds[$categoryId]),
                ));

                if ($activeDescendantIds !== []) {
                    if ($this->keepLegacyCategoryActive($legacyCategory, $counters)) {
                        $keptActiveCategoryIds[$entry['category_id']] = true;
                    }

                    $warnings[] = new CatalogPartTypeRepairIssue(
                        code: 'legacy_category_kept_active_active_descendant',
                        message: sprintf(
                            'Категория «%s» оставлена активной: после переноса в ветке остались активные технические потомки (%d).',
                            $entry['category_path'],
                            count($activeDescendantIds),
                        ),
                        context: [
                            'category_id' => $entry['category_id'],
                            'active_descendant_ids' => $activeDescendantIds,
                        ],
                    );

                    continue;
                }

                $remainingProducts = Product::withTrashed()
                    ->where('product_category_id', $legacyCategory->getKey())
                    ->count();

                if ($remainingProducts > 0) {
                    if ($this->keepLegacyCategoryActive($legacyCategory, $counters)) {
                        $keptActiveCategoryIds[$entry['category_id']] = true;
                    }

                    $warnings[] = new CatalogPartTypeRepairIssue(
                        code: 'legacy_category_kept_active_remaining_products',
                        message: sprintf(
                            'Категория «%s» оставлена активной: после переноса с ней всё ещё связано товаров — %d.',
                            $entry['category_path'],
                            $remainingProducts,
                        ),
                        context: [
                            'category_id' => $entry['category_id'],
                            'remaining_products' => $remainingProducts,
                        ],
                    );

                    continue;
                }

                if ($legacyCategory->is_active) {
                    $legacyCategory->forceFill(['is_active' => false])->saveQuietly();
                    $counters['technical_categories_deactivated']++;
                }
            }

            return new CatalogPartTypeRepairResult($counters, $warnings);
        });
    }

    /** @param array<string, int> $counters */
    private function keepLegacyCategoryActive(ProductCategory $category, array &$counters): bool
    {
        if ($category->trashed()) {
            return false;
        }

        if (! $category->is_active) {
            $category->forceFill(['is_active' => true])->saveQuietly();
        }

        $counters['technical_categories_kept_active']++;

        return true;
    }

    private function repairStoreStructure(CatalogPartTypeRepairPlan $plan, array &$counters): void
    {
        $definitions = $this->categoryRegistry->indexedDefinitions();
        $body = $this->ensureCategory($definitions['kuzovnye-detali'], null);
        $repairRoot = $this->ensureCategory($definitions['kuzovnye-detali/remontnye-elementy-kuzova'], $body);

        foreach ($plan->legacyStoreCategories as $entry) {
            $legacy = ProductCategory::withTrashed()->lockForUpdate()->find($entry['category_id']);

            if (! $legacy instanceof ProductCategory) {
                throw new RuntimeException("Старая магазинная категория #{$entry['category_id']} не найдена.");
            }

            $canonical = ProductCategory::withTrashed()
                ->lockForUpdate()
                ->where('full_slug', $entry['canonical_path'])
                ->first();

            if ($canonical instanceof ProductCategory && (int) $canonical->getKey() !== (int) $legacy->getKey()) {
                if ($entry['action'] === 'already_merged') {
                    continue;
                }

                $products = Product::withTrashed()->where('product_category_id', $legacy->getKey());
                $importedProducts = (clone $products)
                    ->whereNotNull('import_key')
                    ->where('import_key', '!=', '')
                    ->count();
                $productsUpdated = $products->update(['product_category_id' => $canonical->getKey()]);
                $counters['imported_products_updated'] += $importedProducts;
                $counters['manual_products_updated'] += max(0, $productsUpdated - $importedProducts);
                $changed = $productsUpdated > 0;

                $changed = PartType::withTrashed()
                    ->where('product_category_id', $legacy->getKey())
                    ->update(['product_category_id' => $canonical->getKey()]) > 0 || $changed;

                if ($legacy->is_active) {
                    $legacy->forceFill(['is_active' => false])->saveQuietly();
                    $changed = true;
                }

                if ($canonical->trashed()) {
                    $canonical->restoreQuietly();
                    $changed = true;
                }

                if (! $canonical->is_active) {
                    $canonical->forceFill(['is_active' => true])->saveQuietly();
                    $changed = true;
                }

                if ($changed) {
                    $counters['legacy_store_categories_merged']++;
                }

                continue;
            }

            if ($legacy->trashed()) {
                $legacy->restoreQuietly();
            }

            $legacy->unsetRelation('parent');
            $legacy->forceFill([
                'parent_id' => $repairRoot->getKey(),
                'is_active' => true,
            ])->save();
            $counters['legacy_store_categories_moved']++;
        }

        $categories = [
            'kuzovnye-detali' => $body,
            'kuzovnye-detali/remontnye-elementy-kuzova' => $repairRoot,
        ];

        foreach ($this->categoryRegistry->definitions() as $definition) {
            if (isset($categories[$definition['full_slug']])) {
                continue;
            }

            $parent = $definition['parent_full_slug'] !== null
                ? ($categories[$definition['parent_full_slug']] ?? ProductCategory::query()->where('full_slug', $definition['parent_full_slug'])->first())
                : null;

            $categories[$definition['full_slug']] = $this->ensureCategory($definition, $parent);
        }
    }

    /**
     * @param array{full_slug: string, parent_full_slug: ?string, title: string, slug: string, position: int} $definition
     */
    private function ensureCategory(array $definition, ?ProductCategory $parent): ProductCategory
    {
        $category = ProductCategory::withTrashed()
            ->lockForUpdate()
            ->where('full_slug', $definition['full_slug'])
            ->first();

        if (! $category instanceof ProductCategory) {
            $category = new ProductCategory;
            $category->forceFill([
                'parent_id' => $parent?->getKey(),
                'title' => $definition['title'],
                'slug' => $definition['slug'],
                'full_slug' => $definition['full_slug'],
                'depth' => $parent instanceof ProductCategory ? $parent->depth + 1 : 0,
                'position' => $definition['position'],
                'is_active' => true,
            ])->saveQuietly();

            return $category;
        }

        if ($category->trashed()) {
            $category->restoreQuietly();
        }

        if (! $category->is_active) {
            $category->forceFill(['is_active' => true])->saveQuietly();
        }

        return $category;
    }

    /** @param array<string, int> $counters @return array<string, PartType> */
    private function ensureKnownPartTypes(array &$counters): array
    {
        $partTypes = [];

        foreach ($this->partTypeRegistry->flattened($this->tree) as $definition) {
            $parent = $definition['parent_full_slug'] !== null
                ? ($partTypes[$definition['parent_full_slug']] ?? null)
                : null;
            $partType = PartType::withTrashed()
                ->lockForUpdate()
                ->where('full_slug', $definition['full_slug'])
                ->first();

            if (! $partType instanceof PartType) {
                $partType = new PartType;
                $partType->forceFill([
                    'parent_id' => $parent?->getKey(),
                    'title' => $definition['title'],
                    'position' => $definition['position'],
                    'is_active' => true,
                    'default_image_key' => $definition['default_image_key'],
                ]);
                $this->tree->save($partType);
                $counters['part_types_created']++;
            } else {
                $counters['part_types_existing']++;
            }

            if ($partType->trashed()) {
                $partType->restoreQuietly();
                $counters['part_types_restored']++;
            }

            $updates = [];

            if (! $partType->is_active) {
                $updates['is_active'] = true;
            }

            if (($partType->default_image_key === null || $partType->default_image_key === '') && $definition['default_image_key'] !== null) {
                $updates['default_image_key'] = $definition['default_image_key'];
            }

            if ($partType->product_category_id === null) {
                $updates['product_category_id'] = $this->resolver->resolve($partType)->category->getKey();
            }

            if ($updates !== []) {
                $partType->forceFill($updates)->saveQuietly();
            }

            $partTypes[$definition['full_slug']] = $partType;
        }

        return $partTypes;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, PartType> $partTypes
     * @param array<string, int> $counters
     */
    private function ensureUnknownPartType(array $entry, array &$partTypes, array &$counters): PartType
    {
        $segments = explode('/', $entry['part_type_path']);
        $titles = $entry['part_type_titles'];
        $currentPath = array_shift($segments);
        $parent = $partTypes[$currentPath] ?? PartType::withTrashed()->lockForUpdate()->where('full_slug', $currentPath)->first();

        if (! $parent instanceof PartType) {
            throw new RuntimeException("Корневой PartType «{$currentPath}» не найден.");
        }

        foreach ($segments as $index => $slug) {
            $currentPath .= '/'.$slug;
            $partType = PartType::withTrashed()->lockForUpdate()->where('full_slug', $currentPath)->first();

            if (! $partType instanceof PartType) {
                $partType = new PartType;
                $partType->forceFill([
                    'parent_id' => $parent->getKey(),
                    'title' => $titles[$index + 1] ?? Str::headline($slug),
                    'position' => 100 + (($index + 1) * 10),
                    'is_active' => true,
                    'default_image_key' => null,
                ]);
                $this->tree->save($partType);
                $counters['part_types_created']++;
            } else {
                $counters['part_types_existing']++;
            }

            if ($partType->trashed()) {
                $partType->restoreQuietly();
                $counters['part_types_restored']++;
            }

            $updates = [];

            if (! $partType->is_active) {
                $updates['is_active'] = true;
            }

            if ($partType->product_category_id === null) {
                $updates['product_category_id'] = $this->resolver->resolve($partType)->category->getKey();
            }

            if ($updates !== []) {
                $partType->forceFill($updates)->saveQuietly();
            }

            $partTypes[$currentPath] = $partType;
            $parent = $partTypes[$currentPath];
        }

        return $parent;
    }

    /** @param array<string, mixed> $entry @param array<string, int> $counters */
    private function repairProductsForTechnicalCategory(
        array $entry,
        PartType $partType,
        ProductCategory $category,
        array &$counters,
    ): void {
        $base = Product::withTrashed()->where('product_category_id', $entry['category_id']);
        $dirty = (clone $base)->where(function ($query) use ($partType, $category): void {
            $query->where('product_type', '!=', ProductType::AutoPart->value)
                ->orWhereNull('part_type_id')
                ->orWhere('part_type_id', '!=', $partType->getKey())
                ->orWhere('product_category_id', '!=', $category->getKey());
        });

        $total = (clone $base)->count();
        $dirtyCount = (clone $dirty)->count();
        $imported = (clone $dirty)->whereNotNull('import_key')->where('import_key', '!=', '')->count();
        $manual = $dirtyCount - $imported;

        if ($dirtyCount > 0) {
            $dirty->update([
                'product_type' => ProductType::AutoPart->value,
                'part_type_id' => $partType->getKey(),
                'product_category_id' => $category->getKey(),
                'updated_at' => now(),
            ]);
        }

        $counters['imported_products_updated'] += $imported;
        $counters['manual_products_updated'] += $manual;
        $counters['products_already_correct'] += max(0, $total - $dirtyCount);
    }
}
