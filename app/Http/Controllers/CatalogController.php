<?php

namespace App\Http\Controllers;

use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\PublicCatalogCache;
use App\Services\PublicVehicleCatalogVisibility;
use App\Services\Seo\SeoMetadataService;
use App\Services\Seo\SeoMetaService;
use App\Services\Storefront\VehicleModelCardImageResolver;
use App\Services\StorefrontProductAvailability;
use App\ViewModels\ProductCardViewModel;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class CatalogController extends Controller
{
    public function __construct(
        private readonly SeoMetaService $genericSeo,
        private readonly SeoMetadataService $entitySeo,
        private readonly PublicCatalogCache $catalogCache,
        private readonly StorefrontProductAvailability $availability,
        private readonly PublicVehicleCatalogVisibility $vehicleVisibility,
        private readonly VehicleModelCardImageResolver $modelCardImages,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        $makeSlug = trim((string) $request->query('make', ''));
        $modelSlug = trim((string) $request->query('model', ''));

        if (str_contains($modelSlug, ':')) {
            [$makeSlug, $modelSlug] = array_pad(explode(':', $modelSlug, 2), 2, '');
        }

        if ($makeSlug !== '') {
            $make = $this->vehicleVisibility->makes(VehicleMake::query())
                ->where('slug', $makeSlug)
                ->firstOrFail();

            if ($modelSlug !== '') {
                $model = $this->vehicleVisibility->models($make->models())
                    ->where('slug', $modelSlug)
                    ->firstOrFail();

                return redirect()->route('catalog.model', [$make->slug, $model->slug]);
            }

            return redirect()->route('catalog.make', $make->slug);
        }

        $query = trim((string) $request->query('q', ''));
        $categorySlug = trim((string) $request->query('category', ''));
        $partTypeSlug = trim((string) $request->query('part_type', ''));
        $category = $categorySlug === '' ? null : ProductCategory::query()->active()->where('full_slug', $categorySlug)->firstOrFail();
        $partType = $partTypeSlug === '' ? null : PartType::query()->where('is_active', true)->where('full_slug', $partTypeSlug)->firstOrFail();

        if ($query !== '' || $category instanceof ProductCategory || $partType instanceof PartType) {
            $heading = $category?->title ?? $partType?->title ?? 'Результаты поиска';
            $seo = match (true) {
                $category instanceof ProductCategory => $this->entitySeo->forView(
                    $category,
                    route('catalog.index', ['category' => $category->full_slug]),
                ),
                $partType instanceof PartType => $this->entitySeo->forView(
                    $partType,
                    route('catalog.index', ['part_type' => $partType->full_slug]),
                ),
                default => $this->genericSeo->search($query ?: $heading),
            };
            $seoViewData = $seo->toViewData();
            $vehicleResults = $query === '' ? $this->emptyVehicleSearchResults() : $this->searchVehicleItems($query);

            return view('catalog', array_merge($seoViewData, [
                'headingTitle' => $seoViewData['seoH1'] ?? $heading,
                'searchQuery' => $query,
                'breadcrumbs' => $this->breadcrumbs(),
                'vehicleMakes' => $vehicleResults['makes'],
                'vehicleModels' => $vehicleResults['models'],
                'vehicleGenerations' => $vehicleResults['generations'],
                'products' => $this->filteredProducts($query, $category, $partType),
            ]));
        }

        $makes = $this->catalogCache->activeMakes();

        return view('catalog', array_merge($this->genericSeo->catalog()->toViewData(), [
            'headingTitle' => 'Выберите марку',
            'searchQuery' => '',
            'breadcrumbs' => $this->breadcrumbs(),
            'vehicleMakes' => $makes->map(fn (VehicleMake $make): array => [
                'title' => $make->title,
                'url' => route('catalog.make', $make->slug),
                'image' => $make->image_url,
            ]),
            'vehicleModels' => collect(),
            'vehicleGenerations' => collect(),
            'products' => collect(),
        ]));
    }

    public function make(string $makeSlug): View
    {
        $make = $this->vehicleVisibility->makes(VehicleMake::query())
            ->where('slug', $makeSlug)
            ->firstOrFail();
        $models = $this->vehicleVisibility->models($make->models())
            ->withCount(['generations' => fn (Builder $query) => $this->vehicleVisibility->generations($query)])
            ->orderBy('position')->orderBy('title')->get();
        $modelImages = $this->modelCardImages->resolve($models);

        return view('brand', array_merge($this->entitySeo->forView(
            $make,
            route('catalog.make', $make->slug),
        )->toViewData(), [
            'make' => $make,
            'models' => $models,
            'modelImages' => $modelImages,
            'breadcrumbs' => $this->breadcrumbs([['label' => $make->title]]),
        ]));
    }

    public function model(string $makeSlug, string $modelSlug): View
    {
        $make = $this->vehicleVisibility->makes(VehicleMake::query())
            ->where('slug', $makeSlug)
            ->firstOrFail();
        $model = $this->vehicleVisibility->models($make->models())
            ->where('slug', $modelSlug)
            ->firstOrFail();
        $generations = $this->vehicleVisibility->generations($model->generations())
            ->orderBy('position')
            ->orderBy('title')
            ->orderBy('years_label')
            ->orderBy('body')
            ->orderBy('id')
            ->get();
        $generationGroups = $generations
            ->groupBy(fn (VehicleGeneration $generation): string => mb_strtolower(trim($generation->title)).'|'.trim((string) $generation->years_label))
            ->map(function (Collection $group): array {
                /** @var VehicleGeneration $generation */
                $generation = $group->first();

                return [
                    'title' => $generation->title,
                    'years_label' => $generation->years_label,
                    'generations' => $group->values(),
                ];
            })
            ->values();
        $otherModels = $this->vehicleVisibility->models($make->models())
            ->whereKeyNot($model->getKey())
            ->withCount(['generations' => fn (Builder $query) => $this->vehicleVisibility->generations($query)])
            ->orderBy('position')
            ->orderBy('title')
            ->orderBy('id')
            ->limit(8)
            ->get();
        $otherModelImages = $this->modelCardImages->resolve($otherModels);

        return view('model', array_merge($this->entitySeo->forView(
            $model,
            route('catalog.model', [$make->slug, $model->slug]),
        )->toViewData(), [
            'make' => $make,
            'model' => $model,
            'generations' => $generations,
            'generationGroups' => $generationGroups,
            'otherModels' => $otherModels,
            'otherModelImages' => $otherModelImages,
            'breadcrumbs' => $this->breadcrumbs([
                ['label' => $make->title, 'url' => route('catalog.make', $make->slug)],
                ['label' => $model->title],
            ]),
        ]));
    }

    public function generation(Request $request, string $makeSlug, string $modelSlug, string $generationSlug): View
    {
        $make = $this->vehicleVisibility->makes(VehicleMake::query())
            ->where('slug', $makeSlug)
            ->firstOrFail();
        $model = $this->vehicleVisibility->models($make->models())
            ->where('slug', $modelSlug)
            ->firstOrFail();
        $generation = $this->vehicleVisibility->generations($model->generations())
            ->where('slug', $generationSlug)
            ->firstOrFail();
        $search = trim((string) $request->query('q', ''));
        $categorySlug = trim((string) $request->query('category', ''));
        $partTypeSlug = trim((string) $request->query('part_type', ''));
        $selectedCategory = $categorySlug === '' ? null : ProductCategory::query()->active()->where('full_slug', $categorySlug)->firstOrFail();
        $selectedPartType = $partTypeSlug === '' ? null : PartType::query()->where('is_active', true)->where('full_slug', $partTypeSlug)->firstOrFail();

        $productsQuery = $this->activeProductCardQuery()
            ->whereHas('fitments', fn ($query) => $query->where('vehicle_generation_id', $generation->getKey()));

        if ($search !== '') {
            $this->applyProductSearch($productsQuery, $search);
        }
        if ($selectedCategory instanceof ProductCategory) {
            $productsQuery->whereIn('product_category_id', $this->categoryIds($selectedCategory));
        }
        if ($selectedPartType instanceof PartType) {
            $productsQuery->whereIn('part_type_id', $this->partTypeIds($selectedPartType));
        }

        $products = $productsQuery->orderBy('position')->orderBy('title')->paginate(12)->withQueryString();
        $categories = ProductCategory::query()->active()
            ->whereHas('products', fn (Builder $query): Builder => $this->availability->products($query)
                ->whereHas('variants', fn (Builder $variantQuery): Builder => $this->availability->variants($variantQuery))
                ->whereHas('fitments', fn (Builder $fitmentQuery): Builder => $fitmentQuery->where('vehicle_generation_id', $generation->getKey())))
            ->orderBy('position')->orderBy('title')->get();

        $seoViewData = $this->entitySeo->forView(
            $generation,
            route('catalog.generation', [$make->slug, $model->slug, $generation->slug]),
        )->toViewData();

        return view('car', array_merge($seoViewData, [
            'make' => $make,
            'model' => $model,
            'generation' => $generation,
            'headingTitle' => $seoViewData['seoH1'] ?? 'Кузовные элементы для '.$make->title.' '.$model->title,
            'breadcrumbs' => $this->breadcrumbs([
                ['label' => $make->title, 'url' => route('catalog.make', $make->slug)],
                ['label' => $model->title, 'url' => route('catalog.model', [$make->slug, $model->slug])],
                ['label' => trim($make->title.' '.$model->title.' '.$generation->title)],
            ]),
            'categories' => $categories,
            'selectedCategory' => $selectedCategory,
            'selectedPartType' => $selectedPartType,
            'products' => $products->through(fn (Product $product): ProductCardViewModel => ProductCardViewModel::fromProduct($product)),
            'searchQuery' => $search,
            'generationImage' => $generation->image_url,
        ]));
    }

    private function activeProductCardQuery(): Builder
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

    /** @return array<int, array{label:string,url?:string}> */
    private function breadcrumbs(array $tail = []): array
    {
        return array_merge([
            ['label' => 'Главная', 'url' => route('home')],
            ['label' => 'Каталог', 'url' => route('catalog.index')],
        ], $tail);
    }

    /** @return array<int, int> */
    private function categoryIds(ProductCategory $category): array
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
    private function partTypeIds(PartType $partType): array
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
    private function searchVehicleItems(string $query): array
    {
        $makes = $this->vehicleVisibility->makes(VehicleMake::query())->where('title', 'like', '%'.$query.'%')
            ->orderBy('position')->orderBy('title')->limit(10)->get()
            ->toBase()
            ->map(fn (VehicleMake $make): array => ['title' => $make->title, 'url' => route('catalog.make', $make->slug), 'image' => $make->image_url]);
        $modelRecords = $this->vehicleVisibility->models(VehicleModel::query())
            ->where(fn (Builder $builder): Builder => $builder
                ->where('title', 'like', '%'.$query.'%')
                ->orWhereHas('make', fn (Builder $makeQuery): Builder => $makeQuery->active()->where('title', 'like', '%'.$query.'%')))
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
            ->where(fn (Builder $builder): Builder => $builder
                ->where('title', 'like', '%'.$query.'%')
                ->orWhere('years_label', 'like', '%'.$query.'%')
                ->orWhere('body', 'like', '%'.$query.'%')
                ->orWhereHas('model', fn (Builder $modelQuery): Builder => $modelQuery
                    ->where('title', 'like', '%'.$query.'%')
                    ->orWhereHas('make', fn (Builder $makeQuery): Builder => $makeQuery->where('title', 'like', '%'.$query.'%'))))
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
    private function emptyVehicleSearchResults(): array
    {
        return [
            'makes' => collect(),
            'models' => collect(),
            'generations' => collect(),
        ];
    }

    private function filteredProducts(string $query, ?ProductCategory $category, ?PartType $partType): LengthAwarePaginator
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

    private function applyProductSearch(Builder $products, string $query): void
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
}
