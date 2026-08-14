<?php

namespace App\Services\Seo;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\ViewData\Storefront\GlobalStorefrontData;
use Illuminate\Support\Str;

class SeoMetaService
{
    public function __construct(private readonly GlobalStorefrontData $storefront) {}

    public function home(): SeoData
    {
        $storeName = $this->storefront->storeName;

        return new SeoData(
            $storeName.' — кузовные пороги, арки и автотовары',
            'Интернет-магазин кузовных порогов, арок и деталей для популярных автомобилей. Подбор по марке, модели и поколению.',
            route('home'),
        );
    }

    public function catalog(): SeoData
    {
        $storeName = $this->storefront->storeName;

        return new SeoData(
            'Каталог автотоваров по маркам — '.$storeName,
            'Выберите марку, модель и поколение автомобиля, чтобы найти подходящие кузовные пороги, арки и другие детали.',
            route('catalog.index'),
        );
    }

    public function search(string $query): SeoData
    {
        $storeName = $this->storefront->storeName;

        return new SeoData(
            'Результаты поиска — '.$storeName,
            'Результаты поиска по каталогу автотоваров '.$storeName.'.',
            route('catalog.index'),
        );
    }

    public function make(VehicleMake $make): SeoData
    {
        $storeName = $this->storefront->storeName;

        return new SeoData(
            $make->meta_title ?: $make->title.' — каталог моделей — '.$storeName,
            $make->meta_description ?: 'Каталог кузовных деталей и автотоваров для автомобилей '.$make->title.'. Выберите модель и поколение.',
            route('catalog.make', $make->slug),
        );
    }

    public function model(VehicleMake $make, VehicleModel $model): SeoData
    {
        $name = trim($make->title.' '.$model->title);
        $storeName = $this->storefront->storeName;

        return new SeoData(
            $model->meta_title ?: $name.' — поколения и детали — '.$storeName,
            $model->meta_description ?: 'Кузовные детали и автотовары для '.$name.'. Выберите поколение автомобиля для точного подбора.',
            route('catalog.model', [$make->slug, $model->slug]),
        );
    }

    public function generation(VehicleMake $make, VehicleModel $model, VehicleGeneration $generation): SeoData
    {
        $name = trim($make->title.' '.$model->title.' '.$generation->title.' '.$generation->years_label.' '.$generation->body);
        $storeName = $this->storefront->storeName;

        return new SeoData(
            $generation->meta_title ?: 'Кузовные элементы для '.$name.' — '.$storeName,
            $generation->meta_description ?: 'Подбор кузовных деталей и автотоваров для '.$name.'. Активные товары с ценами и наличием.',
            route('catalog.generation', [$make->slug, $model->slug, $generation->slug]),
        );
    }

    public function category(ProductCategory $category): SeoData
    {
        $storeName = $this->storefront->storeName;

        return new SeoData(
            $category->meta_title ?: $category->title.' — каталог товаров — '.$storeName,
            $category->meta_description ?: 'Каталог товаров категории «'.$category->title.'» в интернет-магазине '.$storeName.'.',
            route('catalog.index', ['category' => $category->full_slug]),
        );
    }

    public function product(Product $product): SeoData
    {
        $description = $product->meta_description ?: $product->short_description ?: $product->description;
        $storeName = $this->storefront->storeName;

        return new SeoData(
            ($product->meta_title ?: $product->title).' — '.$storeName,
            $description ? Str::limit(trim(strip_tags((string) $description)), 160, '') : null,
            route('products.show', $product->slug),
        );
    }
}
