<?php

namespace Database\Seeders;

use App\Exceptions\Catalog\CatalogCategoryStructureConflictException;
use App\Models\ProductCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductCatalogSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $bodyParts = $this->ensureCategory(
                fullSlug: 'kuzovnye-detali',
                title: 'Кузовные детали',
                slug: 'kuzovnye-detali',
                position: 10,
            );

            $repairParts = $this->ensureCategory(
                fullSlug: 'kuzovnye-detali/remontnye-elementy-kuzova',
                title: 'Ремонтные элементы кузова',
                slug: 'remontnye-elementy-kuzova',
                position: 10,
                parent: $bodyParts,
            );

            $leafCategories = [
                ['Пороги', 'porogi', 10],
                ['Арки', 'arki', 20],
                ['Лонжероны', 'lonzherony', 30],
                ['Ремкомплекты пола', 'remkomplekty-pola', 40],
                ['Заглушки', 'zaglushki', 50],
                ['Усилители', 'usiliteli', 60],
                ['Пенные вставки', 'pennye-vstavki', 70],
            ];

            foreach ($leafCategories as [$title, $slug, $position]) {
                $this->ensureCategory(
                    fullSlug: $repairParts->full_slug.'/'.$slug,
                    title: $title,
                    slug: $slug,
                    position: $position,
                    parent: $repairParts,
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
