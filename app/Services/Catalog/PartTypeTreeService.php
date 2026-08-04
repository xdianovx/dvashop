<?php

namespace App\Services\Catalog;

use App\Exceptions\Catalog\PartTypeCycleException;
use App\Models\PartType;
use App\Models\ProductCategory;
use App\Support\CatalogText;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PartTypeTreeService
{
    public function __construct(
        private readonly CatalogRelationIdNormalizer $relationIds,
    ) {}

    public function save(PartType $partType): PartType
    {
        return DB::transaction(function () use ($partType): PartType {
            $shouldRecalculateDescendants = $partType->exists && $partType->isDirty(['title', 'parent_id']);

            $this->prepareForSave($partType);
            app(CatalogStructureAdminService::class)->assertPartTypeIdentityAvailable($partType);
            $partType->saveQuietly();

            if ($shouldRecalculateDescendants) {
                $this->recalculateDescendants($partType);
            }

            return $partType;
        });
    }

    public function prepareForSave(PartType $partType): void
    {
        $title = CatalogText::plain($partType->title, 255);
        $parentId = $this->relationIds->nullablePositive($partType->parent_id, 'parent_id');
        $categoryId = $this->relationIds->nullablePositive($partType->product_category_id, 'product_category_id');
        $partType->forceFill([
            'parent_id' => $parentId,
            'product_category_id' => $categoryId,
        ]);

        $parent = $this->ensureAcyclic($partType, $parentId);
        $this->validateProductCategory($partType, $categoryId);
        $slug = $this->slugForTitle($title);

        $partType->forceFill([
            'title' => $title,
            'slug' => $slug,
            'full_slug' => CatalogText::slugPath([$parent?->full_slug, $slug], 255),
            'full_title' => CatalogText::plain(
                $parent instanceof PartType ? $parent->full_title.' / '.$title : $title,
                255,
            ),
            'depth' => $parent instanceof PartType ? $parent->depth + 1 : 0,
            'position' => $partType->position ?? 0,
            'is_active' => $partType->is_active ?? true,
        ]);
    }

    public function recalculateDescendants(PartType $partType): void
    {
        DB::transaction(function () use ($partType): void {
            $visited = [];

            if ($partType->getKey() !== null) {
                $visited[(int) $partType->getKey()] = true;
            }

            $this->recalculateChildren($partType, $visited);
        });
    }

    /** @return array<int, int> */
    public function descendantIds(PartType|int $partType): array
    {
        $rootId = $partType instanceof PartType ? (int) $partType->getKey() : $partType;
        $childrenByParent = PartType::withTrashed()
            ->get(['id', 'parent_id'])
            ->groupBy(fn (PartType $candidate): int => (int) ($candidate->parent_id ?? 0));
        $descendants = [];
        $frontier = [$rootId];

        while ($frontier !== []) {
            $parentId = array_shift($frontier);

            foreach ($childrenByParent->get($parentId, collect()) as $child) {
                $childId = (int) $child->getKey();

                if (in_array($childId, $descendants, true)) {
                    continue;
                }

                $descendants[] = $childId;
                $frontier[] = $childId;
            }
        }

        return $descendants;
    }

    public function slugForTitle(string $title): string
    {
        $source = strtr(mb_strtolower(CatalogText::plain($title, 255)), [
            'ж' => 'zh',
            'ц' => 'ts',
            'ч' => 'ch',
            'ш' => 'sh',
            'щ' => 'shch',
            'х' => 'kh',
        ]);

        return CatalogText::slug($source, 'part-type', 120);
    }

    private function ensureAcyclic(PartType $partType, ?int $parentId): ?PartType
    {
        $partTypeId = $partType->getKey();

        if ($parentId === null) {
            return null;
        }

        $visited = $partTypeId === null ? [] : [(int) $partTypeId => true];
        $currentId = $parentId;
        $selectedParent = null;

        while ($currentId !== null) {
            if (isset($visited[$currentId])) {
                throw PartTypeCycleException::forPartType((string) $partType->title);
            }

            $visited[$currentId] = true;
            $parent = PartType::withTrashed()
                ->whereKey($currentId)
                ->lockForUpdate()
                ->first();

            if (! $parent instanceof PartType) {
                $this->fail('parent_id', 'Выбранный родительский тип детали не существует.');
            }

            if ($parent->trashed()) {
                $this->fail('parent_id', 'Удалённый тип детали не может быть родительским.');
            }

            $selectedParent ??= $parent;
            $currentId = $this->relationIds->nullablePositive($parent->parent_id, 'parent_id');
        }

        return $selectedParent;
    }

    private function validateProductCategory(PartType $partType, ?int $categoryId): void
    {
        if ($categoryId === null) {
            return;
        }

        $category = ProductCategory::withTrashed()
            ->whereKey($categoryId)
            ->lockForUpdate()
            ->first();

        if (! $category instanceof ProductCategory) {
            $this->fail('product_category_id', 'Выбранная категория не существует.');
        }

        if ($category->trashed()) {
            $this->fail('product_category_id', 'Удалённая категория не может быть назначена типу детали.');
        }

        $originalId = $partType->exists
            ? $this->relationIds->nullablePositive($partType->getRawOriginal('product_category_id'), 'product_category_id')
            : null;

        if (! $category->is_active && (! $partType->exists || $originalId !== $categoryId)) {
            $this->fail('product_category_id', 'Нельзя назначить типу детали неактивную категорию.');
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    /** @param array<int, bool> $visited */
    private function recalculateChildren(PartType $parent, array &$visited): void
    {
        $children = PartType::withTrashed()
            ->where('parent_id', $parent->getKey())
            ->orderBy('position')
            ->orderBy('title')
            ->get();

        foreach ($children as $child) {
            $childId = (int) $child->getKey();

            if (isset($visited[$childId])) {
                throw PartTypeCycleException::forPartType($child->title);
            }

            $visited[$childId] = true;
            $title = CatalogText::plain($child->title, 255);
            $slug = $this->slugForTitle($title);

            $child->forceFill([
                'title' => $title,
                'slug' => $slug,
                'full_slug' => CatalogText::slugPath([$parent->full_slug, $slug], 255),
                'full_title' => CatalogText::plain($parent->full_title.' / '.$title, 255),
                'depth' => $parent->depth + 1,
            ]);
            app(CatalogStructureAdminService::class)->assertPartTypeIdentityAvailable($child);
            $child->saveQuietly();

            $this->recalculateChildren($child, $visited);
        }
    }
}
