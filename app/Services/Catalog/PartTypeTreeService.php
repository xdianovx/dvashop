<?php

namespace App\Services\Catalog;

use App\Exceptions\Catalog\PartTypeCycleException;
use App\Models\PartType;
use App\Support\CatalogText;
use Illuminate\Support\Facades\DB;

class PartTypeTreeService
{
    public function save(PartType $partType): PartType
    {
        return DB::transaction(function () use ($partType): PartType {
            $shouldRecalculateDescendants = $partType->exists && $partType->isDirty(['title', 'parent_id']);

            $this->prepareForSave($partType);
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
        $this->ensureAcyclic($partType);

        $parent = $this->findParent($partType);
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

    private function ensureAcyclic(PartType $partType): void
    {
        $partTypeId = $partType->getKey();
        $parentId = $partType->parent_id;

        if ($partTypeId === null || $parentId === null) {
            return;
        }

        $visited = [(int) $partTypeId => true];
        $currentId = (int) $parentId;

        while ($currentId > 0) {
            if (isset($visited[$currentId])) {
                throw PartTypeCycleException::forPartType((string) $partType->title);
            }

            $visited[$currentId] = true;
            $currentId = (int) (PartType::withTrashed()->whereKey($currentId)->value('parent_id') ?? 0);
        }
    }

    private function findParent(PartType $partType): ?PartType
    {
        if ($partType->parent_id === null) {
            return null;
        }

        return PartType::withTrashed()->find($partType->parent_id);
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
            ])->saveQuietly();

            $this->recalculateChildren($child, $visited);
        }
    }
}
