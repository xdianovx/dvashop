<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ProductCategory;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PublicProductCategoryVisibility
{
    public function idsQuery(): Builder
    {
        // MySQL 8 / SQLite: resolve all ancestors inside the original catalog query.
        // Starting at roots also excludes orphans/cycles without a depth limit in PHP.
        return DB::query()->fromRaw('(WITH RECURSIVE public_categories AS (
            SELECT id FROM product_categories WHERE parent_id IS NULL AND is_active = 1 AND deleted_at IS NULL
            UNION ALL
            SELECT child.id FROM product_categories AS child
            INNER JOIN public_categories AS parent ON child.parent_id = parent.id
            WHERE child.is_active = 1 AND child.deleted_at IS NULL
        ) SELECT id FROM public_categories) AS visible_category_ids')->select('id');
    }

    /** @return Collection<int, ProductCategory> */
    public function categories(): Collection
    {
        $remaining = ProductCategory::query()->whereIn('id', $this->idsQuery())->orderBy('id')->get()->keyBy('id');
        $public = collect();

        do {
            $added = false;
            foreach ($remaining as $id => $category) {
                if ($category->parent_id === null || $public->has($category->parent_id)) {
                    $category->setRelation('parent', $public->get($category->parent_id));
                    $public->put($id, $category);
                    $remaining->forget($id);
                    $added = true;
                }
            }
        } while ($added);

        // Unreachable children, deleted parents and cycles are never public.
        return $public;
    }

    public function allows(?ProductCategory $category): bool
    {
        $seen = [];
        while ($category !== null) {
            if (! $category->is_active || $category->trashed() || isset($seen[$category->id])) {
                return false;
            }
            $seen[$category->id] = true;
            if ($category->parent_id === null) {
                return true;
            }
            $category = $category->parent;
        }

        return false;
    }
}
