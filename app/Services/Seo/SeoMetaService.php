<?php

namespace App\Services\Seo;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Support\Str;

class SeoMetaService
{
    public function home(): SeoData
    {
        return new SeoData(
            '2POROGA — кузовные пороги, арки и автотовары',
            'Интернет-магазин кузовных порогов, арок и деталей для популярных автомобилей. Подбор по марке, модели и поколению.',
            route('home'),
        );
    }

    public function catalog(): SeoData
    {
        return new SeoData(
            'Каталог автотоваров по маркам — 2POROGA',
            'Выберите марку, модель и поколение автомобиля, чтобы найти подходящие кузовные пороги, арки и другие детали.',
            route('catalog.index'),
        );
    }

    public function search(string $query): SeoData
    {
        return new SeoData(
            'Результаты поиска — 2POROGA',
            'Результаты поиска по каталогу автотоваров 2POROGA.',
            route('catalog.index'),
        );
    }

    public function make(VehicleMake $make): SeoData
    {
        return new SeoData(
            $make->meta_title ?: $make->title.' — каталог моделей — 2POROGA',
            $make->meta_description ?: 'Каталог кузовных деталей и автотоваров для автомобилей '.$make->title.'. Выберите модель и поколение.',
            route('catalog.make', $make->slug),
        );
    }

    public function model(VehicleMake $make, VehicleModel $model): SeoData
    {
        $name = trim($make->title.' '.$model->title);

        return new SeoData(
            $model->meta_title ?: $name.' — поколения и детали — 2POROGA',
            $model->meta_description ?: 'Кузовные детали и автотовары для '.$name.'. Выберите поколение автомобиля для точного подбора.',
            route('catalog.model', [$make->slug, $model->slug]),
        );
    }

    public function generation(VehicleMake $make, VehicleModel $model, VehicleGeneration $generation): SeoData
    {
        $name = trim($make->title.' '.$model->title.' '.$generation->title.' '.$generation->years_label.' '.$generation->body);

        return new SeoData(
            $generation->meta_title ?: 'Кузовные элементы для '.$name.' — 2POROGA',
            $generation->meta_description ?: 'Подбор кузовных деталей и автотоваров для '.$name.'. Активные товары с ценами и наличием.',
            route('catalog.generation', [$make->slug, $model->slug, $generation->slug]),
        );
    }

    public function category(ProductCategory $category): SeoData
    {
        return new SeoData(
            $category->meta_title ?: $category->title.' — каталог товаров — 2POROGA',
            $category->meta_description ?: 'Каталог товаров категории «'.$category->title.'» в интернет-магазине 2POROGA.',
            route('catalog.category', $category->full_slug),
        );
    }

    public function product(Product $product): SeoData
    {
        $description = $product->meta_description ?: $product->short_description ?: $product->description;

        return new SeoData(
            ($product->meta_title ?: $product->title).' — 2POROGA',
            $description ? Str::limit(trim(strip_tags((string) $description)), 160, '') : null,
            route('products.show', $product->slug),
        );
    }
}
