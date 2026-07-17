<?php

namespace Database\Seeders;

use App\Exceptions\Catalog\CatalogCategoryStructureConflictException;
use App\Models\ProductCategory;
use App\Services\Catalog\ProductCategoryCatalogRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductCatalogSeeder extends Seeder
{
    public function run(ProductCategoryCatalogRegistry $registry): void
    {
        DB::transaction(function () use ($registry): void {
            $categories = [];

            foreach ($registry->definitions() as $definition) {
                $parent = $definition['parent_full_slug'] !== null
                    ? ($categories[$definition['parent_full_slug']] ?? null)
                    : null;

                $categories[$definition['full_slug']] = $this->ensureCategory(
                    fullSlug: $definition['full_slug'],
                    title: $definition['title'],
                    slug: $definition['slug'],
                    position: $definition['position'],
                    parent: $parent,
                );
            }
        });
    }

    private function ensureCategory(
        string $fullSlug,
        string $title,
        string $slug,
        int $position,
        ?ProductCategory $parent = null,
    ): ProductCategory {
        $category = ProductCategory::withTrashed()->where('full_slug', $fullSlug)->first();

        if ($category instanceof ProductCategory) {
            $expectedParentId = $parent?->getKey();

            if ($category->parent_id !== $expectedParentId || $category->slug !== $slug) {
                throw CatalogCategoryStructureConflictException::forPath($fullSlug, $category->full_slug);
            }

            if ($category->trashed()) {
                $category->restoreQuietly();
            }

            if (! $category->is_active) {
                $category->forceFill(['is_active' => true])->saveQuietly();
            }

            return $category;
        }

        $conflict = ProductCategory::withTrashed()
            ->where('title', $title)
            ->where('slug', $slug)
            ->first();

        if ($conflict instanceof ProductCategory) {
            throw CatalogCategoryStructureConflictException::forPath($fullSlug, $conflict->full_slug);
        }

        $category = new ProductCategory;
        $category->forceFill([
            'parent_id' => $parent?->getKey(),
            'title' => $title,
            'slug' => $slug,
            'full_slug' => $fullSlug,
            'depth' => $parent instanceof ProductCategory ? $parent->depth + 1 : 0,
            'position' => $position,
            'is_active' => true,
        ])->saveQuietly();

        return $category;
    }
}
