<?php

namespace App\Services\Search;

use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\PublicVehicleCatalogVisibility;
use App\Services\Storefront\VehicleModelCardImageResolver;
use App\Services\StorefrontProductAvailability;
use App\ViewModels\ProductCardViewModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class DatabaseCatalogSearchProvider
{
    public function __construct(
        private readonly StorefrontProductAvailability $availability,
        private readonly PublicVehicleCatalogVisibility $vehicleVisibility,
        private readonly VehicleModelCardImageResolver $modelCardImages,
    ) {}

    public function search(string $query, ?ProductCategory $category = null, ?PartType $partType = null): array
    {
        return ($query === '' ? $this->emptyVehicleSearchResults() : $this->searchVehicleItems($query))
            + ['products' => $this->filteredProducts($query, $category, $partType)];
    }

    public function activeProductCardQuery(): Builder
    {
        return $this->availability->products(Product::query())
            ->whereHas('variants', fn (Builder $query): Builder => $this->availability->variants($query))
            ->with([
                'variants' => fn ($query) => $this->availability->variants($query)
                    ->orderByDesc('is_default')
                    ->orderBy('id'),
                'variants.optionValues.group',
                'mainImage',
                'visibleImages',
                'category',
                'partType',
            ]);
    }

    /** @return array<int, int> */
    public function categoryIds(ProductCategory $category): array
    {
        return ProductCategory::query()
            ->active()
            ->where(fn (Builder $query): Builder => $query
                ->where('full_slug', $category->full_slug)
                ->orWhere('full_slug', 'like', $category->full_slug.'/%'))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /** @return array<int, int> */
    public function partTypeIds(PartType $partType): array
    {
        return PartType::query()
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query
                ->whereKey($partType->getKey())
                ->orWhere('full_slug', 'like', $partType->full_slug.'/%'))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @return array{
     *     makes: Collection<int, array{title:string,url:string,image:string}>,
     *     models: Collection<int, array{make_title:string,model_title:string,url:string,generation_count:int,image:?string}>,
     *     generations: Collection<int, array{make_title:string,model_title:string,title:string,body:string,years_label:string,image:string,url:string}>
     * }
     */
    public function searchVehicleItems(string $query, ?array $ids = null): array
    {
        $makes = $this->vehicleVisibility->makes(VehicleMake::query())->when($ids !== null, fn (Builder $q) => $this->ranked($q, $ids['makes']), fn (Builder $q) => $q->where('title', 'like', '%'.$query.'%'))
            ->orderBy('position')->orderBy('title')->limit(10)->get()
            ->toBase()
            ->map(fn (VehicleMake $make): array => ['title' => $make->title, 'url' => route('catalog.make', $make->slug), 'image' => $make->image_url]);
        $modelRecords = $this->vehicleVisibility->models(VehicleModel::query())
            ->when($ids !== null, fn (Builder $q) => $this->ranked($q, $ids['models']), fn (Builder $q) => $q->where(fn (Builder $builder): Builder => $builder
                ->where('title', 'like', '%'.$query.'%')
                ->orWhereHas('make', fn (Builder $makeQuery): Builder => $makeQuery->active()->where('title', 'like', '%'.$query.'%'))))
            ->with('make')
            ->withCount(['generations' => fn (Builder $generationQuery) => $this->vehicleVisibility->generations($generationQuery)])
            ->orderBy('position')->orderBy('title')->limit(10)->get();
        $modelImages = $this->modelCardImages->resolve($modelRecords);
        $models = $modelRecords
            ->toBase()
            ->map(fn (VehicleModel $model): array => [
                'make_title' => $model->make->title,
                'model_title' => $model->title,
                'url' => route('catalog.model', [$model->make->slug, $model->slug]),
                'generation_count' => (int) $model->generations_count,
                'image' => $modelImages->get((int) $model->getKey()),
            ]);
        $generations = $this->vehicleVisibility->generations(VehicleGeneration::query())
            ->when($ids !== null, fn (Builder $q) => $this->ranked($q, $ids['generations']), fn (Builder $q) => $q->where(fn (Builder $builder): Builder => $builder
                ->where('title', 'like', '%'.$query.'%')
                ->orWhere('years_label', 'like', '%'.$query.'%')
                ->orWhere('body', 'like', '%'.$query.'%')
                ->orWhereHas('model', fn (Builder $modelQuery): Builder => $modelQuery
                    ->where('title', 'like', '%'.$query.'%')
                    ->orWhereHas('make', fn (Builder $makeQuery): Builder => $makeQuery->where('title', 'like', '%'.$query.'%')))))
            ->with('model.make')->orderBy('position')->orderBy('title')->limit(10)->get()
            ->toBase()
            ->map(fn (VehicleGeneration $generation): array => [
                'make_title' => $generation->model->make->title,
                'model_title' => $generation->model->title,
                'title' => $generation->title,
                'body' => (string) $generation->body,
                'years_label' => (string) $generation->years_label,
                'image' => $generation->image_url,
                'url' => route('catalog.generation', [$generation->model->make->slug, $generation->model->slug, $generation->slug]),
            ]);

        return [
            'makes' => $makes,
            'models' => $models,
            'generations' => $generations,
        ];
    }

    /**
     * @return array{
     *     makes: Collection<int, array{title:string,url:string,image:string}>,
     *     models: Collection<int, array{make_title:string,model_title:string,url:string,generation_count:int,image:?string}>,
     *     generations: Collection<int, array{make_title:string,model_title:string,title:string,body:string,years_label:string,image:string,url:string}>
     * }
     */
    public function emptyVehicleSearchResults(): array
    {
        return [
            'makes' => collect(),
            'models' => collect(),
            'generations' => collect(),
        ];
    }

    public function filteredProducts(string $query, ?ProductCategory $category, ?PartType $partType): LengthAwarePaginator
    {
        $products = $this->activeProductCardQuery();
        if ($query !== '') {
            $this->applyProductSearch($products, $query);
        }
        if ($category instanceof ProductCategory) {
            $products->whereIn('product_category_id', $this->categoryIds($category));
        }
        if ($partType instanceof PartType) {
            $products->whereIn('part_type_id', $this->partTypeIds($partType));
        }

        return $products->orderBy('position')->orderBy('title')->paginate(12)->withQueryString()
            ->through(fn (Product $product): ProductCardViewModel => ProductCardViewModel::fromProduct($product));
    }

    public function applyProductSearch(Builder $products, string $query): void
    {
        $pattern = '%'.$query.'%';
        $candidateIds = Product::query()
            ->where(fn (Builder $productQuery): Builder => $productQuery
                ->where('title', 'like', $pattern)
                ->orWhere(fn (Builder $skuQuery): Builder => $skuQuery
                    ->whereNotNull('sku')
                    ->where('sku', '<>', '')
                    ->where('sku', 'like', $pattern))
                ->orWhereHas('variants', fn (Builder $variantQuery): Builder => $this->availability->variants($variantQuery)
                    ->whereNotNull('sku')
                    ->where('sku', '<>', '')
                    ->where('sku', 'like', $pattern)))
            ->pluck('products.id');

        $products->whereKey($candidateIds);
    }

    /**
     * Visibility-only batch for bounded Meilisearch vehicle refill.
     * Relevance order is inherited from the candidate id order.
     *
     * @param  array{makes:list<int>,models:list<int>,generations:list<int>}  $ids
     * @return array{makes:list<int>,models:list<int>,generations:list<int>}
     */
    public function publicVehicleIds(array $ids): array
    {
        $queries = [
            'makes' => $this->vehicleVisibility->makes(VehicleMake::query()),
            'models' => $this->vehicleVisibility->models(VehicleModel::query()),
            'generations' => $this->vehicleVisibility->generations(VehicleGeneration::query()),
        ];
        $result = [];
        foreach ($queries as $group => $query) {
            $candidates = $ids[$group] ?? [];
            if ($candidates === []) {
                $result[$group] = [];

                continue;
            }
            $result[$group] = $this->ranked($query, $candidates)
                ->pluck($query->getModel()->getQualifiedKeyName())
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
        }

        return $result;
    }

    public function ranked(Builder $query, array $ids): Builder
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $query->whereKey($ids);
        if ($ids !== []) {
            $cases = [];
            foreach ($ids as $rank => $id) {
                $cases[] = 'when '.$id.' then '.$rank;
            }
            $query->orderByRaw('case '.$query->getModel()->getQualifiedKeyName().' '.implode(' ', $cases).' end');
        }

        return $query;
    }

    /** Visibility-only batch: card relations are loaded once, after refill finishes. */
    public function publicProductIds(array $ids, ?ProductCategory $category, ?PartType $partType, array $vehicleFilters = []): array
    {
        $query = $this->availability->products(Product::query())
            ->whereHas('variants', fn (Builder $variants) => $this->availability->variants($variants));
        $this->ranked($query, $ids);
        if ($category) {
            $query->whereIn('product_category_id', $this->categoryIds($category));
        }
        if ($partType) {
            $query->whereIn('part_type_id', $this->partTypeIds($partType));
        }
        if ($vehicleFilters !== []) {
            $query->whereHas('fitments.generation', function (Builder $generation) use ($vehicleFilters): void {
                $this->vehicleVisibility->generations($generation);
                if (($vehicleFilters['generation_id'] ?? null) !== null) {
                    $generation->whereKey((int) $vehicleFilters['generation_id']);
                }
                if (($vehicleFilters['model_id'] ?? null) !== null) {
                    $generation->where('vehicle_model_id', (int) $vehicleFilters['model_id']);
                }
                if (($vehicleFilters['make_id'] ?? null) !== null) {
                    $generation->whereHas('model', fn (Builder $model): Builder => $model->where('vehicle_make_id', (int) $vehicleFilters['make_id']));
                }
            });
        }

        return $query->pluck('products.id')->map(fn ($id) => (int) $id)->all();
    }

    public function hydrateProducts(array $ids): Collection
    {
        return $this->ranked($this->activeProductCardQuery(), $ids)->limit(12)->get()
            ->map(fn (Product $product) => ProductCardViewModel::fromProduct($product));
    }
}
