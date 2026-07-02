<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\VehicleGeneration;
use App\Services\Seo\SeoMetaService;
use App\ViewModels\ProductCardViewModel;
use Illuminate\Contracts\View\View;

class ProductController extends Controller
{
    public function __construct(private readonly SeoMetaService $seo) {}

    public function show(string $productSlug): View
    {
        $product = Product::query()
            ->active()
            ->where('slug', $productSlug)
            ->whereHas('defaultVariant', fn ($query) => $query->where('is_active', true))
            ->with([
                'category.parent',
                'defaultVariant',
                'variants' => fn ($query) => $query->where('is_active', true)->orderByDesc('is_default')->orderBy('id'),
                'images' => fn ($query) => $query->orderByDesc('is_main')->orderBy('position')->orderBy('id'),
                'fitments.generation.model.make',
            ])
            ->firstOrFail();

        /** @var ProductVariant $variant */
        $variant = $product->defaultVariant;
        $primaryFitment = $product->fitments->sortByDesc('is_primary')->first();
        $generation = $primaryFitment?->generation;
        $model = $generation?->model;
        $make = $model?->make;

        $gallery = $product->images->isNotEmpty()
            ? $product->images->map(fn (ProductImage $image): array => [
                'url' => ProductCardViewModel::imageUrl($image->path),
                'alt' => $image->alt ?: $product->title,
            ])
            : collect([['url' => '/img/products/threshold.png', 'alt' => $product->title]]);

        $related = Product::query()
            ->active()
            ->whereKeyNot($product->getKey())
            ->whereHas('defaultVariant', fn ($query) => $query->where('is_active', true))
            ->where(function ($query) use ($product, $generation): void {
                if ($product->product_category_id) {
                    $query->where('product_category_id', $product->product_category_id);
                }

                if ($generation instanceof VehicleGeneration) {
                    $method = $product->product_category_id ? 'orWhereHas' : 'whereHas';
                    $query->{$method}('fitments', fn ($fitmentQuery) => $fitmentQuery->where('vehicle_generation_id', $generation->getKey()));
                }
            })
            ->with([
                'defaultVariant',
                'images' => fn ($query) => $query->orderByDesc('is_main')->orderBy('position')->orderBy('id'),
                'category',
            ])
            ->orderBy('position')
            ->orderBy('title')
            ->limit(4)
            ->get()
            ->map(fn (Product $relatedProduct): ProductCardViewModel => ProductCardViewModel::fromProduct($relatedProduct));

        return view('part', array_merge($this->seo->product($product)->toViewData(), [
            'product' => $product,
            'variant' => $variant,
            'gallery' => $gallery,
            'related' => $related,
            'make' => $make,
            'model' => $model,
            'generation' => $generation,
            'breadcrumbs' => $this->breadcrumbs($product, $generation),
            'description' => $product->description ?: $product->short_description,
        ]));
    }

    /**
     * @return array<int, array{label: string, url?: string}>
     */
    private function breadcrumbs(Product $product, ?VehicleGeneration $generation): array
    {
        $items = [
            ['label' => 'Главная', 'url' => route('home')],
            ['label' => 'Каталог', 'url' => route('catalog.index')],
        ];

        $model = $generation?->model;
        $make = $model?->make;

        if ($make) {
            $items[] = ['label' => $make->title, 'url' => route('catalog.make', $make->slug)];
        }

        if ($make && $model) {
            $items[] = ['label' => $model->title, 'url' => route('catalog.model', [$make->slug, $model->slug])];
        }

        if ($make && $model && $generation) {
            $items[] = ['label' => $generation->title, 'url' => route('catalog.generation', [$make->slug, $model->slug, $generation->slug])];
        }

        if ($product->category) {
            $items[] = ['label' => $product->category->title, 'url' => route('catalog.category', $product->category->full_slug)];
        }

        $items[] = ['label' => $product->title];

        return $items;
    }
}
