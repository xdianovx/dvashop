<?php

namespace App\Services\Catalog;

use App\Models\PartType;
use App\Models\ProductCategory;
use App\Support\CatalogText;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CatalogPartTypeRepairInspector
{
    public function __construct(
        private readonly LegacyTechnicalCategoryMap $legacyMap,
        private readonly ProductCategoryCatalogRegistry $categoryRegistry,
        private readonly PartTypeDefinitionRegistry $partTypeRegistry,
        private readonly PartTypeTreeService $tree,
        private readonly PartTypeCategoryResolver $resolver,
    ) {}

    public function inspect(bool $lock = false): CatalogPartTypeRepairPlan
    {
        $categoryQuery = ProductCategory::withTrashed()
            ->select([
                'id',
                'parent_id',
                'title',
                'slug',
                'full_slug',
                'depth',
                'position',
                'is_active',
                'deleted_at',
            ])
            ->orderBy('id');

        if ($lock) {
            $categoryQuery->lockForUpdate();
        }

        /** @var EloquentCollection<int, ProductCategory> $categories */
        $categories = $categoryQuery->get();
        $categoriesById = $categories->keyBy(fn (ProductCategory $category): int => (int) $category->getKey());
        $categoriesByPath = $categories->keyBy('full_slug');
        $childrenByParent = $categories->groupBy(fn (ProductCategory $category): int => (int) ($category->parent_id ?? 0));
        $warnings = [];
        $blockers = [];
        $pathMemo = [];
        $pathCycles = [];

        foreach ($categories as $category) {
            $this->categoryTitlePath($category, $categoriesById, $pathMemo, $pathCycles);
        }

        foreach (array_keys($pathCycles) as $categoryId) {
            $blockers[] = new CatalogPartTypeRepairIssue(
                code: 'category_cycle',
                message: "В дереве ProductCategory обнаружен цикл с участием категории #{$categoryId}.",
                context: ['category_id' => $categoryId],
            );
        }

        $this->inspectCanonicalStoreStructure($categories, $categoriesByPath, $childrenByParent, $blockers);
        $this->inspectPartTypeStructure($lock, $blockers);

        $productCounts = DB::table('products')
            ->selectRaw('product_category_id, COUNT(*) as total_count')
            ->selectRaw("SUM(CASE WHEN import_key IS NULL OR import_key = '' THEN 1 ELSE 0 END) as manual_count")
            ->selectRaw("SUM(CASE WHEN import_key IS NOT NULL AND import_key != '' THEN 1 ELSE 0 END) as imported_count")
            ->groupBy('product_category_id')
            ->get()
            ->keyBy('product_category_id');

        $legacyStoreCategories = [];
        $partTypeCountsByCategory = DB::table('part_types')
            ->selectRaw('product_category_id, COUNT(*) as total_count')
            ->groupBy('product_category_id')
            ->pluck('total_count', 'product_category_id');

        foreach ($this->categoryRegistry->legacyStorePaths() as $legacyPath => $canonicalPath) {
            $legacy = $categoriesByPath->get($legacyPath);

            if (! $legacy instanceof ProductCategory) {
                continue;
            }

            $canonical = $categoriesByPath->get($canonicalPath);
            $counts = $productCounts->get($legacy->getKey());
            $partTypesCount = (int) ($partTypeCountsByCategory[$legacy->getKey()] ?? 0);
            $productsCount = (int) ($counts->total_count ?? 0);
            $action = 'move';

            if ($canonical instanceof ProductCategory) {
                $requiresMerge = $productsCount > 0
                    || $partTypesCount > 0
                    || $legacy->is_active
                    || $canonical->trashed()
                    || ! $canonical->is_active;
                $action = $requiresMerge ? 'merge' : 'already_merged';
            }

            $legacyStoreCategories[] = [
                'category_id' => (int) $legacy->getKey(),
                'legacy_path' => $legacyPath,
                'canonical_path' => $canonicalPath,
                'canonical_category_id' => $canonical instanceof ProductCategory ? (int) $canonical->getKey() : null,
                'products_count' => $productsCount,
                'imported_products' => (int) ($counts->imported_count ?? 0),
                'manual_products' => (int) ($counts->manual_count ?? 0),
                'part_types_count' => $partTypesCount,
                'action' => $action,
            ];
        }

        $technicalCategories = [];
        $unknownChildren = [];
        $suspects = [];
        $canonicalPaths = array_fill_keys(array_keys($this->categoryRegistry->indexedDefinitions()), true);
        $legacyStorePaths = array_fill_keys(array_keys($this->categoryRegistry->legacyStorePaths()), true);

        foreach ($categories as $category) {
            $fullTitle = $pathMemo[(int) $category->getKey()] ?? CatalogText::plain($category->title, 255);
            $partTypePath = $this->legacyMap->partTypePath($fullTitle);
            $unknownChild = false;

            if ($partTypePath === null && $this->legacyMap->isUnknownChildUnderKnownRoot($fullTitle)) {
                $unknownChild = true;
                $partTypePath = $this->unknownPartTypePath($fullTitle);
            }

            if ($partTypePath !== null) {
                $counts = $productCounts->get($category->getKey());
                $entry = [
                    'category_id' => (int) $category->getKey(),
                    'category_path' => $fullTitle,
                    'category_full_slug' => $category->full_slug,
                    'products_count' => (int) ($counts->total_count ?? 0),
                    'imported_products' => (int) ($counts->imported_count ?? 0),
                    'manual_products' => (int) ($counts->manual_count ?? 0),
                    'part_type_path' => $partTypePath,
                    'part_type_titles' => $this->partTypeTitles($fullTitle, $partTypePath),
                    'store_category_path' => $this->resolver->categoryPathFor($partTypePath),
                    'used_fallback' => $this->resolver->usesFallback($partTypePath),
                    'is_unknown_child' => $unknownChild,
                    'is_active' => (bool) $category->is_active,
                    'is_trashed' => $category->trashed(),
                    'action' => match (true) {
                        ((int) ($counts->total_count ?? 0)) > 0 => 'migrate_products_and_deactivate',
                        (bool) $category->is_active => 'deactivate',
                        default => 'already_deactivated',
                    },
                ];
                $technicalCategories[] = $entry;

                if ($unknownChild) {
                    $unknownChildren[] = $entry;
                    $warnings[] = new CatalogPartTypeRepairIssue(
                        code: 'unknown_child_fallback',
                        message: "Для «{$fullTitle}» используется PartType «{$partTypePath}» с fallback-категорией магазина; отсутствующий PartType будет создан.",
                        context: ['category_id' => $category->getKey(), 'part_type_path' => $partTypePath],
                    );
                }

                continue;
            }

            if (isset($canonicalPaths[$category->full_slug])
                || isset($legacyStorePaths[$category->full_slug])
                || $this->legacyMap->isUnderKnownTechnicalRoot($fullTitle)
                || ! $this->legacyMap->looksSuspicious($fullTitle)) {
                continue;
            }

            $counts = $productCounts->get($category->getKey());
            $suspects[] = [
                'category_id' => (int) $category->getKey(),
                'category_path' => $fullTitle,
                'products_count' => (int) ($counts->total_count ?? 0),
                'reason' => 'Название похоже на технический тип, но безопасного mapping нет.',
            ];
        }

        if ($suspects !== []) {
            $warnings[] = new CatalogPartTypeRepairIssue(
                code: 'suspects_require_manual_review',
                message: 'Обнаружены подозрительные категории, которые требуют ручной проверки и не будут изменены.',
                context: ['count' => count($suspects)],
            );
        }

        $this->inspectUnknownPartTypeStructure($unknownChildren, $lock, $blockers);

        return new CatalogPartTypeRepairPlan(
            legacyStoreCategories: $legacyStoreCategories,
            technicalCategories: $technicalCategories,
            unknownChildren: $unknownChildren,
            suspects: $suspects,
            previewCounters: $this->previewCounters($legacyStoreCategories, $technicalCategories, $unknownChildren, $lock),
            warnings: $warnings,
            blockers: $this->uniqueIssues($blockers),
        );
    }

    /**
     * @param EloquentCollection<int, ProductCategory> $categories
     * @param EloquentCollection<string, ProductCategory> $categoriesByPath
     * @param EloquentCollection<int, EloquentCollection<int, ProductCategory>> $childrenByParent
     * @param array<int, CatalogPartTypeRepairIssue> $blockers
     */
    private function inspectCanonicalStoreStructure(
        EloquentCollection $categories,
        EloquentCollection $categoriesByPath,
        EloquentCollection $childrenByParent,
        array &$blockers,
    ): void {
        $definitions = $this->categoryRegistry->indexedDefinitions();
        $allowedLegacyPaths = array_keys($this->categoryRegistry->legacyStorePaths());

        foreach ($definitions as $path => $definition) {
            $category = $categoriesByPath->get($path);

            if ($category instanceof ProductCategory) {
                $expectedParent = $definition['parent_full_slug'] !== null
                    ? $categoriesByPath->get($definition['parent_full_slug'])
                    : null;
                $expectedParentId = $expectedParent instanceof ProductCategory ? (int) $expectedParent->getKey() : null;

                if ($category->slug !== $definition['slug']) {
                    $blockers[] = new CatalogPartTypeRepairIssue(
                        'canonical_category_slug_conflict',
                        "Канонический путь «{$path}» занят категорией с неожиданным slug «{$category->slug}».",
                        ['category_id' => $category->getKey()],
                    );
                }

                if ($category->title !== $definition['title']) {
                    $blockers[] = new CatalogPartTypeRepairIssue(
                        'canonical_category_title_conflict',
                        "Канонический путь «{$path}» занят категорией с неожиданным названием «{$category->title}».",
                        ['category_id' => $category->getKey()],
                    );
                }

                $expectedDepth = $definition['parent_full_slug'] === null ? 0 : substr_count($path, '/');

                if ((int) $category->depth !== $expectedDepth) {
                    $blockers[] = new CatalogPartTypeRepairIssue(
                        'canonical_category_depth_conflict',
                        "Каноническая категория «{$path}» имеет неожиданную глубину {$category->depth}.",
                        ['category_id' => $category->getKey(), 'expected_depth' => $expectedDepth],
                    );
                }

                if (($definition['parent_full_slug'] === null && $category->parent_id !== null)
                    || ($definition['parent_full_slug'] !== null && $expectedParentId === null)
                    || ($expectedParentId !== null && (int) $category->parent_id !== $expectedParentId)) {
                    $blockers[] = new CatalogPartTypeRepairIssue(
                        'canonical_category_parent_conflict',
                        "Каноническая категория «{$path}» имеет неожиданного родителя.",
                        ['category_id' => $category->getKey()],
                    );
                }

                continue;
            }

            $candidates = $categories->filter(function (ProductCategory $candidate) use ($definition, $allowedLegacyPaths): bool {
                return $candidate->slug === $definition['slug']
                    && $candidate->title === $definition['title']
                    && ! in_array($candidate->full_slug, $allowedLegacyPaths, true);
            });

            if ($candidates->count() > 0) {
                $blockers[] = new CatalogPartTypeRepairIssue(
                    'ambiguous_canonical_category_candidate',
                    "Для «{$path}» найдена категория с тем же title/slug в другом месте дерева.",
                    ['candidate_ids' => $candidates->modelKeys()],
                );
            }
        }

        foreach ($this->categoryRegistry->legacyStorePaths() as $legacyPath => $canonicalPath) {
            $legacy = $categoriesByPath->get($legacyPath);

            if (! $legacy instanceof ProductCategory) {
                continue;
            }

            $canonicalDefinition = $definitions[$canonicalPath];

            if ($legacy->slug !== $canonicalDefinition['slug'] || $legacy->title !== $canonicalDefinition['title']) {
                $blockers[] = new CatalogPartTypeRepairIssue(
                    'legacy_store_category_identity_conflict',
                    "Старая магазинная категория «{$legacyPath}» имеет неожиданные title или slug.",
                    ['category_id' => $legacy->getKey()],
                );
            }

            $children = $childrenByParent->get((int) $legacy->getKey(), collect());

            if ($children->isNotEmpty()) {
                $blockers[] = new CatalogPartTypeRepairIssue(
                    'legacy_store_category_has_children',
                    "Старая магазинная категория «{$legacyPath}» имеет неожиданных дочерних категорий и не может быть перемещена или объединена автоматически.",
                    ['category_id' => $legacy->getKey(), 'child_ids' => $children->modelKeys(), 'canonical_path' => $canonicalPath],
                );
            }

            $body = $categoriesByPath->get('kuzovnye-detali');

            if ($body instanceof ProductCategory && (int) $legacy->parent_id !== (int) $body->getKey()) {
                $blockers[] = new CatalogPartTypeRepairIssue(
                    'legacy_store_category_parent_conflict',
                    "Старая магазинная категория «{$legacyPath}» имеет неожиданного родителя.",
                    ['category_id' => $legacy->getKey()],
                );
            }
        }
    }

    /** @param array<int, CatalogPartTypeRepairIssue> $blockers */
    private function inspectPartTypeStructure(bool $lock, array &$blockers): void
    {
        $query = PartType::withTrashed()->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $partTypes = $query->get();
        $byPath = $partTypes->keyBy('full_slug');
        $definitions = $this->partTypeRegistry->flattened($this->tree);

        foreach ($definitions as $path => $definition) {
            $partType = $byPath->get($path);

            if (! $partType instanceof PartType) {
                continue;
            }

            $expectedParent = $definition['parent_full_slug'] !== null
                ? $byPath->get($definition['parent_full_slug'])
                : null;
            $expectedParentId = $expectedParent instanceof PartType ? (int) $expectedParent->getKey() : null;
            $expectedSlug = basename($path);
            $expectedDepth = substr_count($path, '/');
            $expectedFullTitle = $expectedParent instanceof PartType
                ? $expectedParent->full_title.' / '.$definition['title']
                : $definition['title'];

            if ($partType->slug !== $expectedSlug
                || $partType->title !== $definition['title']
                || $partType->full_title !== $expectedFullTitle
                || (int) $partType->depth !== $expectedDepth
                || ($definition['parent_full_slug'] === null && $partType->parent_id !== null)
                || ($definition['parent_full_slug'] !== null && $expectedParentId === null)
                || ($expectedParentId !== null && (int) $partType->parent_id !== $expectedParentId)) {
                $blockers[] = new CatalogPartTypeRepairIssue(
                    'part_type_structure_conflict',
                    "PartType «{$path}» имеет несовместимую структуру и не может быть исправлен автоматически.",
                    ['part_type_id' => $partType->getKey()],
                );
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $unknownChildren
     * @param array<int, CatalogPartTypeRepairIssue> $blockers
     */
    private function inspectUnknownPartTypeStructure(array $unknownChildren, bool $lock, array &$blockers): void
    {
        if ($unknownChildren === []) {
            return;
        }

        $query = PartType::withTrashed()->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $byPath = $query->get()->keyBy('full_slug');

        foreach ($unknownChildren as $entry) {
            $segments = explode('/', $entry['part_type_path']);
            $titles = $entry['part_type_titles'];
            $currentPath = array_shift($segments);
            $parent = $byPath->get($currentPath);

            foreach ($segments as $index => $slug) {
                $currentPath .= '/'.$slug;
                $partType = $byPath->get($currentPath);

                if (! $partType instanceof PartType) {
                    $parent = null;
                    continue;
                }

                $expectedTitle = $titles[$index + 1] ?? Str::headline($slug);
                $expectedFullTitle = $parent instanceof PartType
                    ? $parent->full_title.' / '.$expectedTitle
                    : $expectedTitle;

                if (! $parent instanceof PartType
                    || (int) $partType->parent_id !== (int) $parent->getKey()
                    || $partType->slug !== $slug
                    || $partType->title !== $expectedTitle
                    || $partType->full_title !== $expectedFullTitle
                    || (int) $partType->depth !== substr_count($currentPath, '/')) {
                    $blockers[] = new CatalogPartTypeRepairIssue(
                        'unknown_part_type_structure_conflict',
                        "PartType «{$currentPath}» уже существует с несовместимой структурой.",
                        ['part_type_id' => $partType->getKey()],
                    );
                }

                $parent = $partType;
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $legacyStoreCategories
     * @param array<int, array<string, mixed>> $technicalCategories
     * @param array<int, array<string, mixed>> $unknownChildren
     * @return array<string, int>
     */
    private function previewCounters(
        array $legacyStoreCategories,
        array $technicalCategories,
        array $unknownChildren,
        bool $lock,
    ): array {
        $counters = CatalogPartTypeRepairResult::emptyCounters();
        $counters['legacy_store_categories_moved'] = count(array_filter(
            $legacyStoreCategories,
            static fn (array $entry): bool => $entry['action'] === 'move',
        ));
        $counters['legacy_store_categories_merged'] = count(array_filter(
            $legacyStoreCategories,
            static fn (array $entry): bool => $entry['action'] === 'merge',
        ));
        $pendingMerges = array_filter(
            $legacyStoreCategories,
            static fn (array $entry): bool => $entry['action'] === 'merge',
        );
        $counters['imported_products_updated'] = array_sum(array_column($technicalCategories, 'imported_products'))
            + array_sum(array_column($pendingMerges, 'imported_products'));
        $counters['manual_products_updated'] = array_sum(array_column($technicalCategories, 'manual_products'))
            + array_sum(array_column($pendingMerges, 'manual_products'));
        $counters['technical_categories_deactivated'] = count(array_filter(
            $technicalCategories,
            static fn (array $entry): bool => $entry['is_active'],
        ));
        $counters['fallback_used'] = count($unknownChildren);

        $query = PartType::withTrashed()->select(['id', 'full_slug', 'deleted_at']);

        if ($lock) {
            $query->lockForUpdate();
        }

        $existing = $query->get()->keyBy('full_slug');
        $desiredPaths = array_keys($this->partTypeRegistry->flattened($this->tree));

        foreach ($unknownChildren as $entry) {
            $path = '';

            foreach (explode('/', $entry['part_type_path']) as $segment) {
                $path = $path === '' ? $segment : $path.'/'.$segment;
                $desiredPaths[] = $path;
            }
        }

        foreach (array_unique($desiredPaths) as $path) {
            $partType = $existing->get($path);

            if (! $partType instanceof PartType) {
                $counters['part_types_created']++;
                continue;
            }

            $counters['part_types_existing']++;

            if ($partType->trashed()) {
                $counters['part_types_restored']++;
            }
        }

        return $counters;
    }

    /** @param array<string, int> $counters */

    private function categoryTitlePath(
        ProductCategory $category,
        EloquentCollection $categoriesById,
        array &$memo,
        array &$cycles,
        array $visiting = [],
    ): string {
        $id = (int) $category->getKey();

        if (isset($memo[$id])) {
            return $memo[$id];
        }

        if (isset($visiting[$id])) {
            $cycles[$id] = true;

            return CatalogText::plain($category->title, 255);
        }

        $visiting[$id] = true;
        $title = CatalogText::plain($category->title, 255);

        if ($category->parent_id === null) {
            return $memo[$id] = $title;
        }

        $parent = $categoriesById->get((int) $category->parent_id);

        if (! $parent instanceof ProductCategory) {
            return $memo[$id] = $title;
        }

        return $memo[$id] = CatalogText::plain(
            $this->categoryTitlePath($parent, $categoriesById, $memo, $cycles, $visiting).' / '.$title,
            1000,
        );
    }

    private function unknownPartTypePath(string $categoryTitlePath): string
    {
        $segments = $this->legacyMap->normalizedSegments($categoryTitlePath);
        $rootPath = $this->legacyMap->partTypePath($segments[0] ?? '');

        if ($rootPath === null) {
            throw new RuntimeException("Не удалось определить корневой PartType для «{$categoryTitlePath}».");
        }

        $slugs = [$rootPath];

        foreach (array_slice($segments, 1) as $segment) {
            $slugs[] = $this->tree->slugForTitle($segment);
        }

        return implode('/', $slugs);
    }

    /** @return array<int, string> */
    private function partTypeTitles(string $categoryTitlePath, string $partTypePath): array
    {
        $known = $this->partTypeRegistry->flattened($this->tree);
        $titles = [];
        $path = '';

        foreach (explode('/', $partTypePath) as $index => $slug) {
            $path = $path === '' ? $slug : $path.'/'.$slug;
            $normalizedSegments = $this->legacyMap->normalizedSegments($categoryTitlePath);
            $titles[] = $known[$path]['title'] ?? Str::ucfirst($normalizedSegments[$index] ?? Str::headline($slug));
        }

        return $titles;
    }

    /** @param array<int, CatalogPartTypeRepairIssue> $issues @return array<int, CatalogPartTypeRepairIssue> */
    private function uniqueIssues(array $issues): array
    {
        $unique = [];

        foreach ($issues as $issue) {
            $key = $issue->code.'|'.$issue->message;
            $unique[$key] = $issue;
        }

        return array_values($unique);
    }
}
