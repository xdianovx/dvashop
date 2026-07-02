<?php

namespace App\Services;

use App\Models\ProductCategory;
use App\Models\VehicleMake;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PublicCatalogCache
{
    private const TTL_SECONDS = 1800;

    /**
     * @return Collection<int, VehicleMake>
     */
    public function activeMakes(): Collection
    {
        if ($this->shouldBypassCache()) {
            return $this->activeMakesQuery();
        }

        $ids = Cache::remember(
            'public_catalog:active_make_ids:v2',
            self::TTL_SECONDS,
            fn (): array => $this->activeMakesQuery()->pluck('id')->all(),
        );

        if ($ids === []) {
            return collect();
        }

        return VehicleMake::query()
            ->active()
            ->whereKey($ids)
            ->withCount(['models' => fn ($query) => $query->active()])
            ->get()
            ->sortBy(fn (VehicleMake $make): int => array_search($make->getKey(), $ids, true))
            ->values();
    }

    /**
     * @return Collection<int, ProductCategory>
     */
    public function popularCategories(int $limit = 12): Collection
    {
        if ($this->shouldBypassCache()) {
            return $this->popularCategoriesQuery($limit);
        }

        $ids = Cache::remember(
            'public_catalog:popular_category_ids:'.$limit.':v2',
            self::TTL_SECONDS,
            fn (): array => $this->popularCategoriesQuery($limit)->pluck('id')->all(),
        );

        if ($ids === []) {
            return collect();
        }

        return ProductCategory::query()
            ->active()
            ->whereKey($ids)
            ->withCount(['products' => fn ($query) => $query->active()])
            ->get()
            ->sortBy(fn (ProductCategory $category): int => array_search($category->getKey(), $ids, true))
            ->values();
    }

    private function shouldBypassCache(): bool
    {
        return app()->runningUnitTests() || app()->environment('testing');
    }

    /**
     * @return Collection<int, VehicleMake>
     */
    private function activeMakesQuery(): Collection
    {
        return VehicleMake::query()
            ->active()
            ->withCount(['models' => fn ($query) => $query->active()])
            ->orderBy('position')
            ->orderBy('title')
            ->get();
    }

    /**
     * @return Collection<int, ProductCategory>
     */
    private function popularCategoriesQuery(int $limit): Collection
    {
        return ProductCategory::query()
            ->active()
            ->withCount(['products' => fn ($query) => $query->active()])
            ->orderByDesc('products_count')
            ->orderBy('position')
            ->orderBy('title')
            ->limit($limit)
            ->get();
    }
}
