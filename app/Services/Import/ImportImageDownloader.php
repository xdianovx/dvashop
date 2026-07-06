<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\VehicleGeneration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ImportImageDownloader
{
    public const MAX_BYTES = 10485760;

    public function download(Product $product, string $url): ProductImage
    {
        $body = $this->downloadBody($url);
        $path = $this->pathFor($product);
        Storage::disk('public')->put($path, $body);

        return ProductImage::query()->updateOrCreate(
            [
                'product_id' => $product->getKey(),
                'path' => $path,
            ],
            [
                'product_variant_id' => $product->defaultVariant?->getKey(),
                'alt' => $product->title,
                'position' => 0,
                'is_main' => true,
            ]
        );
    }

    public function downloadVehicleGenerationImage(VehicleGeneration $generation, string $url): VehicleGeneration
    {
        $body = $this->downloadBody($url);
        $path = $this->vehicleGenerationPathFor($generation);
        Storage::disk('public')->put($path, $body);

        $generation->forceFill(['image' => $path])->save();

        return $generation->refresh();
    }

    public function pathFor(Product $product): string
    {
        $product->loadMissing('category', 'fitments.generation.model.make');
        $generation = $product->fitments->first()?->generation;
        $model = $generation?->model;
        $make = $model?->make;

        return implode('/', [
            'uploads',
            'products',
            $make?->slug ?: 'unknown-make',
            $model?->slug ?: 'unknown-model',
            $generation?->slug ?: 'unknown-generation',
            $product->slug,
            'image.webp',
        ]);
    }

    public function vehicleGenerationPathFor(VehicleGeneration $generation): string
    {
        $generation->loadMissing('model.make');
        $model = $generation->model;
        $make = $model?->make;

        return implode('/', [
            'uploads',
            'vehicles',
            $make?->slug ?: 'unknown-make',
            $model?->slug ?: 'unknown-model',
            $generation->slug ?: 'unknown-generation',
            'image.webp',
        ]);
    }

    private function downloadBody(string $url): string
    {
        $response = Http::timeout(20)->retry(2, 300)->get($url);

        if (! $response->successful()) {
            throw new RuntimeException('Не удалось скачать изображение. HTTP '.$response->status());
        }

        $body = $response->body();
        $size = strlen($body);

        if ($size <= 0) {
            throw new RuntimeException('Скачанное изображение пустое.');
        }

        if ($size > self::MAX_BYTES) {
            throw new RuntimeException('Изображение больше допустимого размера.');
        }

        return $body;
    }
}
