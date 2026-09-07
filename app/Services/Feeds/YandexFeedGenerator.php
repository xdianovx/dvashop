<?php

declare(strict_types=1);

namespace App\Services\Feeds;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\ShopSetting;
use App\Services\Media\MediaUrlService;
use App\Services\PublicProductCategoryVisibility;
use App\Services\Settings\ShopSettingsService;
use App\Services\StorefrontProductAvailability;
use DateTimeInterface;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use XMLWriter;

class YandexFeedGenerator
{
    public function __construct(
        private readonly StorefrontProductAvailability $availability,
        private readonly PublicProductCategoryVisibility $categoryVisibility,
        private readonly MediaUrlService $media,
    ) {}

    /** @return array<string, mixed> */
    public function generate(string $path): array
    {
        $started = microtime(true);
        $generationStartedAt = now((string) config('app.timezone'));
        $categories = $this->categoryVisibility->categories();
        /** @var UrlGenerator $urls */
        $urls = clone app('url');
        $urls->forceRootUrl((string) config('app.url'));
        $urls->forceScheme((string) parse_url((string) config('app.url'), PHP_URL_SCHEME));
        $shop = ShopSetting::query()->where('singleton_key', ShopSetting::SINGLETON_KEY)->first()
            ?? new ShopSetting(ShopSettingsService::defaults());
        $xml = new XMLWriter;
        if (! $xml->openUri($path)) {
            throw new RuntimeException('Cannot open temporary YML file.');
        }
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('yml_catalog');
        $xml->writeAttribute('date', $generationStartedAt->format(DateTimeInterface::RFC3339));
        $xml->startElement('shop');
        $xml->writeElement('name', $this->text($shop->store_name, 255));
        $xml->writeElement('company', $this->text($shop->legal_name ?: $shop->store_name, 255));
        $xml->writeElement('url', $urls->route('home'));
        $xml->startElement('currencies');
        $xml->startElement('currency');
        $xml->writeAttribute('id', 'RUR');
        $xml->writeAttribute('rate', '1');
        $xml->endElement();
        $xml->endElement();
        $xml->startElement('categories');
        foreach ($categories as $category) {
            $xml->startElement('category');
            $xml->writeAttribute('id', (string) $category->id);
            if ($category->parent_id !== null) {
                $xml->writeAttribute('parentId', (string) $category->parent_id);
            }
            $xml->text($this->text($category->title, 255));
            $xml->endElement();
        }
        $xml->endElement();
        $xml->startElement('offers');
        $offers = 0;
        $reasons = [];
        $variants = ProductVariant::query()->with([
            'optionValues.group', 'product.category', 'product.partType', 'product.mainImage',
            'product.visibleImages',
            'product.characteristics' => fn ($query) => $query->visible(),
            'product.fitments' => fn ($query) => $query->whereHas('generation', fn ($generation) => $generation
                ->active()->whereHas('model', fn ($model) => $model->active()->whereHas('make', fn ($make) => $make->active())))->orderBy('id'),
            'product.fitments.generation.model.make',
        ])->lazyById(max(1, (int) config('yandex-feed.chunk_size')));

        foreach ($variants as $variant) {
            $product = $variant->product;
            $reason = null;
            if (! $product instanceof Product) {
                $reason = 'deleted_product';
            } elseif (! $categories->has($product->product_category_id)) {
                $reason = 'missing_or_non_public_category';
            } else {
                // Complete category ancestry is already in memory, no per-offer queries.
                $product->setRelation('category', $categories->get($product->product_category_id));
                if (! $this->availability->isPubliclyAvailable($variant)) {
                    $reason = 'not_public';
                } elseif (! $this->availability->hasSellablePrice($variant)) {
                    $reason = 'invalid_price';
                } elseif (! $this->availability->isPurchasable($variant)) {
                    $reason = 'not_purchasable';
                }
            }
            if ($reason !== null) {
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;

                continue;
            }

            $price = $this->availability->effectivePrice($variant);
            $offerUrl = $urls->route('products.show', ['productSlug' => $product->slug, 'variant' => $variant->id]);
            if (mb_strlen($offerUrl) > 512) {
                $reasons['url_too_long'] = ($reasons['url_too_long'] ?? 0) + 1;

                continue;
            }
            $xml->startElement('offer');
            $xml->writeAttribute('id', 'variant-'.$variant->id);
            $xml->writeAttribute('available', 'true');
            $xml->writeElement('url', $offerUrl);
            $xml->writeElement('price', number_format($price, 2, '.', ''));
            $oldPrice = $variant->old_price ?? $product->old_price;
            if ($oldPrice !== null && (float) $oldPrice > $price) {
                $xml->writeElement('oldprice', number_format((float) $oldPrice, 2, '.', ''));
            }
            $xml->writeElement('currencyId', 'RUR');
            $xml->writeElement('categoryId', (string) $product->product_category_id);
            foreach ($this->pictures($product, $urls) as $picture) {
                $xml->writeElement('picture', $picture);
            }
            $name = collect([$product->title, $variant->title])->filter()->implode(' — ');
            $xml->writeElement('name', $this->text($name, 150));
            if (filled($sku = $variant->sku ?: $product->sku)) {
                $xml->writeElement('vendorCode', $this->text($sku, 255));
            }
            if (filled($description = $product->description ?: $product->short_description)) {
                $xml->writeElement('description', $this->text($description, 3000));
            }
            foreach ($this->params($variant) as $name => $values) {
                $xml->startElement('param');
                $xml->writeAttribute('name', $this->text($name, 255));
                $xml->text($this->text(implode('; ', array_unique($values)), 2000));
                $xml->endElement();
            }
            $xml->endElement();
            $offers++;
            // XMLWriter flushes to disk; never accumulates the whole document.
            $xml->flush();
        }
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();
        $xml->flush();
        unset($xml);
        ksort($reasons);

        return [
            'generation_started_at' => $generationStartedAt->toIso8601String(),
            'categories_count' => $categories->count(), 'offers_count' => $offers,
            'skipped_offers_count' => array_sum($reasons), 'skip_reasons' => $reasons,
            'file_size' => filesize($path), 'duration' => round(microtime(true) - $started, 3),
            'memory_peak_bytes' => memory_get_peak_usage(true),
        ];
    }

    /** @return list<string> */
    private function pictures(Product $product, UrlGenerator $urls): array
    {
        return collect([$product->mainImage])->merge($product->visibleImages)
            ->filter(fn ($image): bool => $image instanceof ProductImage && $image->is_visible
                && ! $image->is_default && $image->source_type !== ProductImage::SOURCE_DEFAULT
                && ($image->disk ?: 'public') === 'public'
                && ! filter_var($image->path, FILTER_VALIDATE_URL)
                && filled($image->path) && Storage::disk('public')->exists($image->path))
            ->unique('id')->map(fn (ProductImage $image): string => $urls->to($this->media->productImageUrl($image)))
            ->unique()->take(10)->values()->all();
    }

    /** @return array<string, list<string>> */
    private function params(ProductVariant $variant): array
    {
        $params = [];
        $add = function (string $name, mixed $value) use (&$params): void {
            if (is_scalar($value) && filled((string) $value)) {
                $params[$name][] = (string) $value;
            }
        };
        $add('Тип детали', $variant->product->partType?->title);
        foreach ($variant->publicOptionsSnapshot() as $key => $option) {
            $add(is_array($option) ? (string) ($option['group'] ?? $key) : (string) $key,
                is_array($option) ? ($option['value'] ?? null) : $option);
        }
        foreach ($variant->product->characteristics as $characteristic) {
            $add($characteristic->name, trim($characteristic->value.' '.$characteristic->unit));
        }
        foreach ($variant->product->fitments as $fitment) {
            $generation = $fitment->generation;
            $add('Марка автомобиля', $generation?->model?->make?->title);
            $add('Модель автомобиля', $generation?->model?->title);
            $add('Поколение', $generation?->title);
            $add('Годы', $generation?->years_label);
            $add('Кузов', $generation?->body);
        }

        return $params;
    }

    private function text(string $value, int $limit): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            throw new RuntimeException('Invalid UTF-8 catalog text.');
        }

        return mb_substr(trim(preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', strip_tags($value))), 0, $limit);
    }
}
