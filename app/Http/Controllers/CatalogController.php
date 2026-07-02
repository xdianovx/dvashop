<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\PublicCatalogCache;
use App\Services\Seo\SeoMetaService;
use App\ViewModels\ProductCardViewModel;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class CatalogController extends Controller
{
    public function __construct(
        private readonly SeoMetaService $seo,
        private readonly PublicCatalogCache $catalogCache,
    ) {}

    public function index(Request $request): View
    {
        $query = trim((string) $request->query('q', ''));

        if ($query !== '') {
            return view('catalog', array_merge($this->seo->search($query)->toViewData(), [
                'headingTitle' => 'Результаты поиска',
                'searchQuery' => $query,
                'breadcrumbs' => $this->breadcrumbs(),
                'items' => $this->searchVehicleItems($query),
                'products' => $this->searchProducts($query),
                'popularCategories' => $this->catalogCache->popularCategories(),
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
                'image' => $make->image ?: '/img/brands/'.$make->slug.'.svg',
            ]),
            'products' => collect(),
            'popularCategories' => $this->catalogCache->popularCategories(),
        ]));
    }

    public function make(string $makeSlug): View
    {
        $make = VehicleMake::query()
            ->active()
            ->where('slug', $makeSlug)
            ->firstOrFail();

        $models = $make->models()
            ->active()
            ->withCount(['generations' => fn ($query) => $query->active()])
            ->orderBy('position')
            ->orderBy('title')
            ->get();

        return view('catalog', array_merge($this->seo->make($make)->toViewData(), [
            'headingTitle' => 'Выберите модель '.$make->title,
            'searchQuery' => '',
            'breadcrumbs' => $this->breadcrumbs([
                ['label' => $make->title],
            ]),
            'items' => $models->map(fn (VehicleModel $model): array => [
                'title' => $model->title,
                'url' => route('catalog.model', [$make->slug, $model->slug]),
                'image' => $make->image ?: '/img/brands/'.$make->slug.'.svg',
            ]),
            'products' => collect(),
            'popularCategories' => $this->catalogCache->popularCategories(),
        ]));
    }

    public function model(string $makeSlug, string $modelSlug): View
    {
        $make = VehicleMake::query()
            ->active()
            ->where('slug', $makeSlug)
            ->firstOrFail();

        $model = $make->models()
            ->active()
            ->where('slug', $modelSlug)
            ->firstOrFail();

        $generations = $model->generations()
            ->active()
            ->orderBy('position')
            ->orderBy('title')
            ->get();

        return view('catalog', array_merge($this->seo->model($make, $model)->toViewData(), [
            'headingTitle' => 'Выберите поколение '.$make->title.' '.$model->title,
            'searchQuery' => '',
            'breadcrumbs' => $this->breadcrumbs([
                ['label' => $make->title, 'url' => route('catalog.make', $make->slug)],
                ['label' => $model->title],
            ]),
            'items' => $generations->map(fn (VehicleGeneration $generation): array => [
                'title' => trim($generation->title.' '.$generation->years_label.' '.$generation->body),
                'url' => route('catalog.generation', [$make->slug, $model->slug, $generation->slug]),
                'image' => $generation->image ?: ($make->image ?: '/img/brands/'.$make->slug.'.svg'),
            ]),
            'products' => collect(),
            'popularCategories' => $this->catalogCache->popularCategories(),
        ]));
    }

    public function generation(Request $request, string $makeSlug, string $modelSlug, string $generationSlug): View
    {
        $make = VehicleMake::query()
            ->active()
            ->where('slug', $makeSlug)
            ->firstOrFail();

        $model = $make->models()
            ->active()
            ->where('slug', $modelSlug)
            ->firstOrFail();

        $generation = $model->generations()
            ->active()
            ->where('slug', $generationSlug)
            ->firstOrFail();

        $search = trim((string) $request->query('q', ''));
        $categorySlug = trim((string) $request->query('category', ''));
        $selectedCategory = null;

        $productsQuery = $this->activeProductCardQuery()
            ->whereHas('fitments', fn ($query) => $query->where('vehicle_generation_id', $generation->getKey()))
            ->orderBy('position')
            ->orderBy('title');

        if ($search !== '') {
            $productsQuery->where(function ($query) use ($search): void {
                $query->where('title', 'like', '%'.$search.'%')
                    ->orWhere('sku', 'like', '%'.$search.'%')
                    ->orWhereHas('defaultVariant', fn ($variantQuery) => $variantQuery->where('sku', 'like', '%'.$search.'%'));
            });
        }

        if ($categorySlug !== '') {
            $selectedCategory = ProductCategory::query()
                ->active()
                ->where('full_slug', $categorySlug)
                ->first();

            if ($selectedCategory instanceof ProductCategory) {
                $productsQuery->whereIn('product_category_id', $this->categoryIds($selectedCategory));
            }
        }

        $products = $productsQuery->get();

        $categories = ProductCategory::query()
            ->active()
            ->whereHas('products', function ($query) use ($generation): void {
                $query->active()
                    ->whereHas('defaultVariant', fn ($variantQuery) => $variantQuery->where('is_active', true))
                    ->whereHas('fitments', fn ($fitmentQuery) => $fitmentQuery->where('vehicle_generation_id', $generation->getKey()));
            })
            ->orderBy('position')
            ->orderBy('title')
            ->get();

        return view('car', array_merge($this->seo->generation($make, $model, $generation)->toViewData(), [
            'make' => $make,
            'model' => $model,
            'generation' => $generation,
            'headingTitle' => 'Кузовные элементы <br> для '.$make->title.' '.$model->title,
            'breadcrumbs' => $this->breadcrumbs([
                ['label' => $make->title, 'url' => route('catalog.make', $make->slug)],
                ['label' => $model->title, 'url' => route('catalog.model', [$make->slug, $model->slug])],
                ['label' => trim($make->title.' '.$model->title.' '.$generation->title)],
            ]),
            'categories' => $categories,
            'selectedCategory' => $selectedCategory,
            'products' => $products->map(fn (Product $product): ProductCardViewModel => ProductCardViewModel::fromProduct($product)),
            'searchQuery' => $search,
        ]));
    }

    public function category(string $categoryFullSlug): View
    {
        $category = ProductCategory::query()
            ->active()
            ->where('full_slug', $categoryFullSlug)
            ->firstOrFail();

        $products = $this->activeProductCardQuery()
            ->whereIn('product_category_id', $this->categoryIds($category))
            ->orderBy('position')
            ->orderBy('title')
            ->get();

        return view('catalog', array_merge($this->seo->category($category)->toViewData(), [
            'headingTitle' => $category->title,
            'searchQuery' => '',
            'breadcrumbs' => $this->breadcrumbs([
                ['label' => $category->parent?->title ?: 'Категории', 'url' => route('catalog.index')],
                ['label' => $category->title],
            ]),
            'items' => collect(),
            'products' => $products->map(fn (Product $product): ProductCardViewModel => ProductCardViewModel::fromProduct($product)),
            'popularCategories' => $this->catalogCache->popularCategories(),
        ]));
    }

    /**
     * @param array<int, array{label: string, url?: string}> $tail
     * @return array<int, array{label: string, url?: string}>
     */
    private function breadcrumbs(array $tail = []): array
    {
        return array_merge([
            ['label' => 'Главная', 'url' => route('home')],
            ['label' => 'Каталог', 'url' => route('catalog.index')],
        ], $tail);
    }

    private function activeProductCardQuery(): Builder
    {
        return Product::query()
            ->active()
            ->whereHas('defaultVariant', fn ($query) => $query->where('is_active', true))
            ->with([
                'defaultVariant',
                'images' => fn ($query) => $query->orderByDesc('is_main')->orderBy('position')->orderBy('id'),
                'category',
            ]);
    }

    /**
     * @return array<int, int>
     */
    private function categoryIds(ProductCategory $category): array
    {
        return array_merge([$category->getKey()], $category->descendantIds());
    }

    /**
     * @return Collection<int, array{title: string, url: string, image: string}>
     */
    private function searchVehicleItems(string $query): Collection
    {
        $makes = VehicleMake::query()
            ->active()
            ->where('title', 'like', '%'.$query.'%')
            ->orderBy('position')
            ->orderBy('title')
            ->limit(10)
            ->get()
            ->map(fn (VehicleMake $make): array => [
                'title' => $make->title,
                'url' => route('catalog.make', $make->slug),
                'image' => $make->image ?: '/img/brands/'.$make->slug.'.svg',
            ]);

        $models = VehicleModel::query()
            ->active()
            ->where(function ($modelQuery) use ($query): void {
                $modelQuery->where('title', 'like', '%'.$query.'%')
                    ->orWhereHas('make', fn ($makeQuery) => $makeQuery->active()->where('title', 'like', '%'.$query.'%'));
            })
            ->with(['make' => fn ($makeQuery) => $makeQuery->where('is_active', true)])
            ->orderBy('position')
            ->orderBy('title')
            ->limit(10)
            ->get()
            ->filter(fn (VehicleModel $model): bool => $model->make instanceof VehicleMake)
            ->map(fn (VehicleModel $model): array => [
                'title' => trim($model->make->title.' '.$model->title),
                'url' => route('catalog.model', [$model->make->slug, $model->slug]),
                'image' => $model->make->image ?: '/img/brands/'.$model->make->slug.'.svg',
            ]);

        $generations = VehicleGeneration::query()
            ->active()
            ->where(function ($generationQuery) use ($query): void {
                $generationQuery->where('title', 'like', '%'.$query.'%')
                    ->orWhere('years_label', 'like', '%'.$query.'%')
                    ->orWhere('body', 'like', '%'.$query.'%')
                    ->orWhereHas('model', fn ($modelQuery) => $modelQuery->active()->where('title', 'like', '%'.$query.'%'));
            })
            ->with(['model.make'])
            ->orderBy('position')
            ->orderBy('title')
            ->limit(10)
            ->get()
            ->filter(fn (VehicleGeneration $generation): bool => $generation->model?->is_active && $generation->model?->make?->is_active)
            ->map(fn (VehicleGeneration $generation): array => [
                'title' => trim($generation->model->make->title.' '.$generation->model->title.' '.$generation->title),
                'url' => route('catalog.generation', [$generation->model->make->slug, $generation->model->slug, $generation->slug]),
                'image' => $generation->image ?: ($generation->model->make->image ?: '/img/brands/'.$generation->model->make->slug.'.svg'),
            ]);

        return $makes->merge($models)->merge($generations)->values();
    }

    /**
     * @return Collection<int, ProductCardViewModel>
     */
    private function searchProducts(string $query): Collection
    {
        return $this->activeProductCardQuery()
            ->where(function ($productQuery) use ($query): void {
                $productQuery->where('title', 'like', '%'.$query.'%')
                    ->orWhere('sku', 'like', '%'.$query.'%')
                    ->orWhereHas('defaultVariant', fn ($variantQuery) => $variantQuery->where('sku', 'like', '%'.$query.'%'));
            })
            ->orderBy('position')
            ->orderBy('title')
            ->limit(12)
            ->get()
            ->map(fn (Product $product): ProductCardViewModel => ProductCardViewModel::fromProduct($product));
    }
}
