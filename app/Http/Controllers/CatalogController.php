<?php

namespace App\Http\Controllers;

use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\PublicCatalogCache;
use App\Services\Seo\SeoMetaService;
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
        private readonly SeoMetaService $seo,
        private readonly PublicCatalogCache $catalogCache,
        private readonly StorefrontProductAvailability $availability,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        $makeSlug = trim((string) $request->query('make', ''));
        $modelSlug = trim((string) $request->query('model', ''));

        if (str_contains($modelSlug, ':')) {
            [$makeSlug, $modelSlug] = array_pad(explode(':', $modelSlug, 2), 2, '');
        }

        if ($makeSlug !== '') {
            $make = VehicleMake::query()->active()->where('slug', $makeSlug)->firstOrFail();

            if ($modelSlug !== '') {
                $model = $make->models()->active()->where('slug', $modelSlug)->firstOrFail();

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
            $seo = $category instanceof ProductCategory ? $this->seo->category($category) : $this->seo->search($query ?: $heading);

            return view('catalog', array_merge($seo->toViewData(), [
                'headingTitle' => $heading,
                'searchQuery' => $query,
                'breadcrumbs' => $this->breadcrumbs(),
                'items' => $query === '' ? collect() : $this->searchVehicleItems($query),
                'products' => $this->filteredProducts($query, $category, $partType),
            ]));
        }

        $makes = $this->catalogCache->activeMakes();

        return view('catalog', array_merge($this->seo->catalog()->toViewData(), [
            'headingTitle' => 'Выберите марку',
            'searchQuery' => '',
            'breadcrumbs' => $this->breadcrumbs(),
            'items' => $makes->map(fn (VehicleMake $make): array => [
                'title' => $make->title,
                'url' => route('catalog.make', $make->slug),
                'image' => $make->image_url,
            ]),
            'products' => collect(),
        ]));
    }

    public function make(string $makeSlug): View
    {
        $make = VehicleMake::query()->active()->where('slug', $makeSlug)->firstOrFail();
        $models = $make->models()->active()
            ->withCount(['generations' => fn ($query) => $query->active()])
            ->orderBy('position')->orderBy('title')->get();

        return view('brand', array_merge($this->seo->make($make)->toViewData(), [
            'make' => $make,
            'models' => $models,
            'breadcrumbs' => $this->breadcrumbs([['label' => $make->title]]),
        ]));
    }

    public function model(string $makeSlug, string $modelSlug): View
    {
        $make = VehicleMake::query()->active()->where('slug', $makeSlug)->firstOrFail();
        $model = $make->models()->active()->where('slug', $modelSlug)->firstOrFail();
        $generations = $model->generations()->active()
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
        $otherModels = $make->models()->active()
            ->whereKeyNot($model->getKey())
            ->withCount(['generations' => fn ($query) => $query->active()])
            ->orderBy('position')
            ->orderBy('title')
            ->orderBy('id')
            ->limit(8)
            ->get();

        return view('model', array_merge($this->seo->model($make, $model)->toViewData(), [
            'make' => $make,
            'model' => $model,
            'generations' => $generations,
            'generationGroups' => $generationGroups,
            'otherModels' => $otherModels,
            'breadcrumbs' => $this->breadcrumbs([
                ['label' => $make->title, 'url' => route('catalog.make', $make->slug)],
                ['label' => $model->title],
            ]),
        ]));
    }

    public function generation(Request $request, string $makeSlug, string $modelSlug, string $generationSlug): View
    {
        $make = VehicleMake::query()->active()->where('slug', $makeSlug)->firstOrFail();
        $model = $make->models()->active()->where('slug', $modelSlug)->firstOrFail();
        $generation = $model->generations()->active()->where('slug', $generationSlug)->firstOrFail();
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

        return view('car', array_merge($this->seo->generation($make, $model, $generation)->toViewData(), [
            'make' => $make,
            'model' => $model,
            'generation' => $generation,
            'headingTitle' => 'Кузовные элементы для '.$make->title.' '.$model->title,
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

    /** @return Collection<int, array{title:string,url:string,image:string}> */
    private function searchVehicleItems(string $query): Collection
    {
        $makes = VehicleMake::query()->active()->where('title', 'like', '%'.$query.'%')
            ->orderBy('position')->orderBy('title')->limit(10)->get()
            ->map(fn (VehicleMake $make): array => ['title' => $make->title, 'url' => route('catalog.make', $make->slug), 'image' => $make->image_url]);
        $models = VehicleModel::query()->active()
            ->where(fn ($builder) => $builder->where('title', 'like', '%'.$query.'%')->orWhereHas('make', fn ($makeQuery) => $makeQuery->active()->where('title', 'like', '%'.$query.'%')))
            ->whereHas('make', fn ($makeQuery) => $makeQuery->active())->with('make')
            ->orderBy('position')->orderBy('title')->limit(10)->get()
            ->map(fn (VehicleModel $model): array => ['title' => $model->make->title.' '.$model->title, 'url' => route('catalog.model', [$model->make->slug, $model->slug]), 'image' => $model->make->image_url]);
        $generations = VehicleGeneration::query()->active()
            ->where(fn ($builder) => $builder->where('title', 'like', '%'.$query.'%')->orWhere('years_label', 'like', '%'.$query.'%')->orWhere('body', 'like', '%'.$query.'%'))
            ->whereHas('model', fn ($modelQuery) => $modelQuery->active()->whereHas('make', fn ($makeQuery) => $makeQuery->active()))
            ->with('model.make')->orderBy('position')->orderBy('title')->limit(10)->get()
            ->map(fn (VehicleGeneration $generation): array => ['title' => $generation->display_title, 'url' => route('catalog.generation', [$generation->model->make->slug, $generation->model->slug, $generation->slug]), 'image' => $generation->image_url]);

        return $makes->merge($models)->merge($generations)->values();
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
        $products->where(fn ($productQuery) => $productQuery->where('title', 'like', '%'.$query.'%')
            ->orWhere('sku', 'like', '%'.$query.'%')
            ->orWhereHas('variants', fn (Builder $variantQuery): Builder => $this->availability->variants($variantQuery)
                ->where('sku', 'like', '%'.$query.'%')));
    }
}
