<?php

namespace App\Services;

use App\Models\ProductCategory;
use App\Models\VehicleMake;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PublicCatalogCache
{
    private const TTL_SECONDS = 1800;

    private const VEHICLE_MAKE_IDS_CACHE_KEY = 'public_catalog:public_make_ids:v3';

    public function __construct(
        private readonly PublicVehicleCatalogVisibility $vehicleVisibility,
    ) {}

    /**
     * @return Collection<int, VehicleMake>
     */
    public function activeMakes(): Collection
    {
        if ($this->shouldBypassCache()) {
            return $this->publicMakesQuery()
                ->withCount(['models' => fn (Builder $query) => $this->vehicleVisibility->models($query)])
                ->get();
        }

        $ids = Cache::remember(
            self::VEHICLE_MAKE_IDS_CACHE_KEY,
            self::TTL_SECONDS,
            fn (): array => $this->publicMakesQuery()->pluck('id')->all(),
        );

        if ($ids === []) {
            return collect();
        }

        return $this->vehicleVisibility->makes(VehicleMake::query())
            ->whereKey($ids)
            ->withCount(['models' => fn (Builder $query) => $this->vehicleVisibility->models($query)])
            ->get()
            ->sortBy(fn (VehicleMake $make): int => array_search($make->getKey(), $ids, true))
            ->values();
    }

    public function invalidateVehicleNavigation(): void
    {
        Cache::forget(self::VEHICLE_MAKE_IDS_CACHE_KEY);
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

    private function publicMakesQuery(): Builder
    {
        /** @var Builder $query */
        $query = $this->vehicleVisibility->makes(VehicleMake::query());

        return $query
            ->orderBy('position')
            ->orderBy('title')
            ->orderBy('id');
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
