<?php

namespace App\Http\Controllers;

use App\Models\DeliveryMethodSetting;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use App\Models\VehicleGeneration;
use App\Services\Media\MediaUrlService;
use App\Services\Seo\SeoMetaService;
use App\Services\StorefrontProductAvailability;
use App\ViewModels\ProductCardViewModel;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ProductController extends Controller
{
    public function __construct(
        private readonly SeoMetaService $seo,
        private readonly MediaUrlService $media,
        private readonly StorefrontProductAvailability $availability,
    ) {}

    public function show(string $productSlug): View
    {
        $product = $this->availability->products(Product::query())
            ->where('slug', $productSlug)
            ->whereHas('variants', fn (Builder $query): Builder => $this->availability->variants($query))
            ->with([
                'category.parent',
                'partType',
                'variants' => fn ($query) => $this->availability->variants($query)
                    ->orderByDesc('is_default')
                    ->orderBy('id'),
                'variants.optionValues' => fn ($query) => $query->where('is_active', true)->whereHas('group', fn ($groupQuery) => $groupQuery->where('is_active', true)),
                'variants.optionValues.group',
                'mainImage',
                'visibleImages',
                'characteristics' => fn ($query) => $query->visible()->orderBy('position')->orderBy('id'),
                'fitments' => fn ($query) => $query->whereHas('generation', fn ($generationQuery) => $generationQuery
                    ->active()
                    ->whereHas('model', fn ($modelQuery) => $modelQuery
                        ->active()
                        ->whereHas('make', fn ($makeQuery) => $makeQuery->active()))),
                'fitments.generation.model.make',
            ])
            ->firstOrFail();

        /** @var ProductVariant|null $variant */
        $variant = $product->variants->firstWhere('is_default', true) ?? $product->variants->first();
        abort_unless($variant instanceof ProductVariant, 404);

        [$optionGroups, $availableValues, $variantMatrix] = $this->optionPresentation($product, $product->variants);
        $primaryFitment = $product->fitments->sortByDesc('is_primary')->first();
        $generation = $primaryFitment?->generation;
        $model = $generation?->model;
        $make = $model?->make;
        $galleryImages = collect();

        if ($product->mainImage instanceof ProductImage) {
            $galleryImages->push($product->mainImage);
        }

        $galleryImages->push(...$product->visibleImages
            ->reject(fn (ProductImage $image): bool => $product->mainImage instanceof ProductImage && $image->is($product->mainImage))
            ->all());

        $gallery = $galleryImages
            ->map(fn (ProductImage $image): array => ['url' => $this->media->productImageUrl($image), 'alt' => $image->alt ?: $product->title])
            ->filter(fn (array $image): bool => filled($image['url']))
            ->values();

        if ($gallery->isEmpty()) {
            $gallery->push(['url' => $this->media->productMainImageUrl($product), 'alt' => $product->title]);
        }

        $related = $this->availability->products(Product::query())->whereKeyNot($product->getKey())
            ->whereHas('variants', fn (Builder $query): Builder => $this->availability->variants($query))
            ->when($product->product_category_id, fn ($query) => $query->where('product_category_id', $product->product_category_id))
            ->when(! $product->product_category_id && $generation instanceof VehicleGeneration, fn ($query) => $query->whereHas('fitments', fn ($fitmentQuery) => $fitmentQuery->where('vehicle_generation_id', $generation->getKey())))
            ->with([
                'variants' => fn ($query) => $this->availability->variants($query)
                    ->orderByDesc('is_default')
                    ->orderBy('id'),
                'mainImage',
                'visibleImages',
                'category',
                'partType',
            ])
            ->orderBy('position')->orderBy('title')->limit(4)->get()
            ->map(fn (Product $relatedProduct): ProductCardViewModel => ProductCardViewModel::fromProduct($relatedProduct));

        return view('part', array_merge($this->seo->product($product)->toViewData(), [
            'product' => $product,
            'variant' => $variant,
            'variants' => $product->variants,
            'optionGroups' => $optionGroups,
            'availableValues' => $availableValues,
            'variantMatrix' => $variantMatrix,
            'deliveryMethods' => DeliveryMethodSetting::query()->active()->ordered()->get(),
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
     * @param  Collection<int, ProductVariant>  $variants
     * @return array{
     *     0: Collection<int, array{id:int, code:string, title:string, input_type:string, values:Collection<int, array{id:int, code:string, title:string}>}>,
     *     1: Collection<int, array{id:int, code:string, title:string, group_id:int}>,
     *     2: array<int, array{variant_id:int, option_values:array<int, array{group_id:int, value_id:int, code:string}>, sku:string, price:string, old_price:?string, stock_status:string, stock_quantity:?int}>
     * }
     */
    private function optionPresentation(Product $product, Collection $variants): array
    {
        $values = $variants
            ->flatMap(fn (ProductVariant $variant): Collection => $variant->optionValues)
            ->filter(fn (ProductOptionValue $value): bool => $value->is_active && $value->group?->is_active)
            ->unique(fn (ProductOptionValue $value): int => (int) $value->getKey())
            ->sortBy(fn (ProductOptionValue $value): string => sprintf(
                '%010d:%010d:%010d:%010d',
                (int) $value->group?->position,
                (int) $value->group?->getKey(),
                (int) $value->position,
                (int) $value->getKey(),
            ))
            ->values();

        $availableValues = $values->map(fn (ProductOptionValue $value): array => [
            'id' => (int) $value->getKey(),
            'code' => (string) ($value->code ?: $value->slug ?: $value->getKey()),
            'title' => $value->title,
            'group_id' => (int) $value->product_option_group_id,
        ]);

        $optionGroups = $values
            ->groupBy(fn (ProductOptionValue $value): int => (int) $value->product_option_group_id)
            ->map(function (Collection $groupValues): array {
                /** @var ProductOptionValue $firstValue */
                $firstValue = $groupValues->first();
                /** @var ProductOptionGroup $group */
                $group = $firstValue->group;

                return [
                    'id' => (int) $group->getKey(),
                    'code' => (string) ($group->code ?: $group->slug ?: $group->getKey()),
                    'title' => $group->title,
                    'input_type' => (string) $group->input_type,
                    'values' => $groupValues->map(fn (ProductOptionValue $value): array => [
                        'id' => (int) $value->getKey(),
                        'code' => (string) ($value->code ?: $value->slug ?: $value->getKey()),
                        'title' => $value->title,
                    ])->values(),
                ];
            })
            ->values();

        $variantMatrix = $variants->map(fn (ProductVariant $availableVariant): array => [
            'variant_id' => (int) $availableVariant->getKey(),
            'option_values' => $availableVariant->optionValues
                ->sortBy(fn (ProductOptionValue $value): int => (int) $value->product_option_group_id)
                ->map(fn (ProductOptionValue $value): array => [
                    'group_id' => (int) $value->product_option_group_id,
                    'value_id' => (int) $value->getKey(),
                    'code' => (string) ($value->code ?: $value->slug ?: $value->getKey()),
                ])
                ->values()
                ->all(),
            'sku' => (string) ($availableVariant->sku ?: $product->sku),
            'price' => (string) ($availableVariant->price ?? $product->price),
            'old_price' => $availableVariant->old_price !== null
                ? (string) $availableVariant->old_price
                : ($product->old_price !== null ? (string) $product->old_price : null),
            'stock_status' => $availableVariant->stock_status->value,
            'stock_quantity' => $availableVariant->stock_quantity,
        ])->values()->all();

        return [$optionGroups, $availableValues, $variantMatrix];
    }

    /** @return array<int, array{label:string,url?:string}> */
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
            $items[] = ['label' => $product->category->title, 'url' => route('catalog.index', ['category' => $product->category->full_slug])];
        }
        $items[] = ['label' => $product->title];

        return $items;
    }
}
