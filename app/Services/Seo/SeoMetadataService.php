<?php

namespace App\Services\Seo;

use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Media\MediaUrlService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class SeoMetadataService
{
    public function __construct(private MediaUrlService $mediaUrls) {}

    /**
     * @return array{
     *     meta_title: string,
     *     meta_description: string|null,
     *     h1: string,
     *     seo_text: string|null,
     *     canonical_url: string|null,
     *     noindex: bool,
     *     og_title: string,
     *     og_description: string|null,
     *     og_image: string|null
     * }
     */
    public function resolve(Model $model): array
    {
        $title = $this->entityTitle($model);
        $fallbackDescription = $this->fallbackDescription($model);
        $metaTitle = $this->plain($model->getAttribute('meta_title')) ?? $title;
        $metaDescription = $this->plain($model->getAttribute('meta_description')) ?? $fallbackDescription;

        return [
            'meta_title' => $metaTitle,
            'meta_description' => $metaDescription,
            'h1' => $this->plain($model->getAttribute('seo_h1')) ?? $this->headingTitle($model),
            'seo_text' => $this->nullableString($model->getAttribute('seo_text')),
            'canonical_url' => $this->plain($model->getAttribute('canonical_url')),
            'noindex' => (bool) $model->getAttribute('noindex'),
            'og_title' => $this->plain($model->getAttribute('og_title')) ?? $metaTitle,
            'og_description' => $this->plain($model->getAttribute('og_description')) ?? $metaDescription,
            'og_image' => $this->resolveOgImage($model),
        ];
    }

    public function forView(Model $model, string $fallbackCanonical): SeoData
    {
        $metadata = $this->resolve($model);

        return new SeoData(
            title: $metadata['meta_title'],
            description: $metadata['meta_description'],
            canonical: $metadata['canonical_url'] ?? $fallbackCanonical,
            h1: $metadata['h1'],
            seoText: $metadata['seo_text'],
            noindex: $metadata['noindex'],
            ogTitle: $metadata['og_title'],
            ogDescription: $metadata['og_description'],
            ogImage: $metadata['og_image'],
        );
    }

    private function entityTitle(Model $model): string
    {
        return match (true) {
            $model instanceof Product, $model instanceof ProductCategory, $model instanceof VehicleMake => (string) $model->title,
            $model instanceof PartType => $this->plain($model->full_title) ?? (string) $model->title,
            $model instanceof VehicleModel => $model->display_title,
            $model instanceof VehicleGeneration => $this->generationTitle($model),
            default => throw new InvalidArgumentException('SEO metadata is not supported for '.get_class($model).'.'),
        };
    }

    // Catalog listings name what the page lists, while meta titles keep the plain entity name.
    private function headingTitle(Model $model): string
    {
        return match (true) {
            $model instanceof VehicleMake => 'Модели автомобилей '.$model->title,
            $model instanceof VehicleModel => 'Поколения модели '.$model->display_title,
            default => $this->entityTitle($model),
        };
    }

    private function generationTitle(VehicleGeneration $generation): string
    {
        $generation->loadMissing('model.make');

        return Str::squish(implode(' ', array_filter([
            $generation->display_title,
            $generation->years_label,
            $generation->body,
        ])));
    }

    private function fallbackDescription(Model $model): ?string
    {
        $source = match (true) {
            $model instanceof Product => $model->short_description ?: $model->description,
            $model instanceof ProductCategory, $model instanceof PartType,
            $model instanceof VehicleMake, $model instanceof VehicleModel,
            $model instanceof VehicleGeneration => $model->getAttribute('description') ?: $model->getAttribute('seo_text'),
            default => null,
        };

        $description = $this->plain($source);

        return $description === null ? null : Str::limit($description, 160, '');
    }

    private function resolveOgImage(Model $model): ?string
    {
        $explicit = $this->mediaUrls->publicDiskUrl($this->plain($model->getAttribute('og_image')));

        if ($explicit !== null) {
            return $explicit;
        }

        if (! $model instanceof Product) {
            return null;
        }

        $model->loadMissing('mainImage');
        $image = $model->getRelation('mainImage');

        if (! $image instanceof ProductImage) {
            return null;
        }

        $url = $this->mediaUrls->productImageUrl($image);

        return $url === $this->mediaUrls->placeholderUrl() ? null : $url;
    }

    private function plain(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        return $value === null ? null : Str::squish(strip_tags($value));
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
