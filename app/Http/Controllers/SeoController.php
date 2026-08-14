<?php

namespace App\Http\Controllers;

use App\Models\LegalDocument;
use App\Models\Product;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\PublicVehicleCatalogVisibility;
use App\Services\Storefront\LegalDocumentRouteMap;
use App\Services\StorefrontProductAvailability;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SeoController extends Controller
{
    public function __construct(
        private readonly StorefrontProductAvailability $availability,
        private readonly PublicVehicleCatalogVisibility $vehicleVisibility,
        private readonly LegalDocumentRouteMap $legalRoutes,
    ) {}

    public function robots(): Response
    {
        $content = implode("\n", [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            'Disallow: /cart',
            'Disallow: /checkout',
            'Disallow: /thanks',
            'Sitemap: '.route('sitemap'),
            '',
        ]);

        return response($content, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap(): Response
    {
        $urls = collect([
            $this->url(route('home'), now(), 'daily', '1.0'),
            $this->url(route('catalog.index'), now(), 'daily', '0.9'),
        ])
            ->merge($this->informationPageUrls())
            ->merge($this->legalDocumentUrls())
            ->merge($this->makeUrls())
            ->merge($this->modelUrls())
            ->merge($this->generationUrls())
            ->merge($this->productUrls())
            ->values();

        return response($this->toXml($urls), 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /**
     * @return Collection<int, array{loc: string, lastmod: string, changefreq: string, priority: string}>
     */
    private function makeUrls(): Collection
    {
        return $this->vehicleVisibility->makes(VehicleMake::query())
            ->where('noindex', false)
            ->orderBy('id')
            ->get(['slug', 'updated_at'])
            ->map(fn (VehicleMake $make): array => $this->url(route('catalog.make', $make->slug), $make->updated_at, 'weekly', '0.8'));
    }

    /**
     * @return Collection<int, array{loc: string, lastmod: string, changefreq: string, priority: string}>
     */
    private function modelUrls(): Collection
    {
        return $this->vehicleVisibility->models(VehicleModel::query())
            ->where('noindex', false)
            ->whereHas('make', fn ($query) => $query->active()->where('noindex', false))
            ->with(['make' => fn ($query) => $query->active()->where('noindex', false)->select(['id', 'slug'])])
            ->orderBy('id')
            ->get(['id', 'vehicle_make_id', 'slug', 'updated_at'])
            ->filter(fn (VehicleModel $model): bool => $model->make instanceof VehicleMake)
            ->map(fn (VehicleModel $model): array => $this->url(route('catalog.model', [$model->make->slug, $model->slug]), $model->updated_at, 'weekly', '0.75'));
    }

    /**
     * @return Collection<int, array{loc: string, lastmod: string, changefreq: string, priority: string}>
     */
    private function generationUrls(): Collection
    {
        return $this->vehicleVisibility->generations(VehicleGeneration::query())
            ->where('noindex', false)
            ->whereHas('model', fn ($query) => $query
                ->active()
                ->where('noindex', false)
                ->whereHas('make', fn ($makeQuery) => $makeQuery->active()->where('noindex', false)))
            ->with([
                'model' => fn ($query) => $query->active()->where('noindex', false)->select(['id', 'vehicle_make_id', 'slug']),
                'model.make' => fn ($query) => $query->active()->where('noindex', false)->select(['id', 'slug']),
            ])
            ->orderBy('id')
            ->get(['id', 'vehicle_model_id', 'slug', 'updated_at'])
            ->filter(fn (VehicleGeneration $generation): bool => $generation->model instanceof VehicleModel && $generation->model->make instanceof VehicleMake)
            ->map(fn (VehicleGeneration $generation): array => $this->url(
                route('catalog.generation', [$generation->model->make->slug, $generation->model->slug, $generation->slug]),
                $generation->updated_at,
                'weekly',
                '0.7',
            ));
    }

    /**
     * @return Collection<int, array{loc: string, lastmod: string, changefreq: string, priority: string}>
     */
    private function productUrls(): Collection
    {
        return $this->availability->products(Product::query())
            ->where('noindex', false)
            ->whereHas('variants', fn ($query) => $this->availability->variants($query))
            ->orderBy('id')
            ->get(['slug', 'updated_at'])
            ->map(fn (Product $product): array => $this->url(route('products.show', $product->slug), $product->updated_at, 'weekly', '0.8'));
    }

    /**
     * @return Collection<int, array{loc: string, lastmod: string, changefreq: string, priority: string}>
     */
    private function informationPageUrls(): Collection
    {
        return collect(['about', 'how', 'payment', 'faq', 'partners'])
            ->map(fn (string $routeName): array => $this->url(route($routeName), now(), 'monthly', '0.6'));
    }

    /**
     * @return Collection<int, array{loc: string, lastmod: string, changefreq: string, priority: string}>
     */
    private function legalDocumentUrls(): Collection
    {
        return LegalDocument::query()
            ->where('is_active', true)
            ->whereNotNull('body')
            ->where('body', '!=', '')
            ->orderBy('id')
            ->get(['code', 'updated_at'])
            ->map(fn (LegalDocument $document): array => $this->url(
                $this->legalRoutes->url($document->code),
                $document->updated_at,
                'monthly',
                '0.5',
            ));
    }

    /**
     * @return array{loc: string, lastmod: string, changefreq: string, priority: string}
     */
    private function url(string $loc, Carbon|string|null $lastModified, string $changeFrequency, string $priority): array
    {
        return [
            'loc' => $loc,
            'lastmod' => $lastModified instanceof Carbon ? $lastModified->toDateString() : now()->toDateString(),
            'changefreq' => $changeFrequency,
            'priority' => $priority,
        ];
    }

    /**
     * @param  Collection<int, array{loc: string, lastmod: string, changefreq: string, priority: string}>  $urls
     */
    private function toXml(Collection $urls): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $url) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>'.e($url['loc'])."</loc>\n";
            $xml .= '    <lastmod>'.$url['lastmod']."</lastmod>\n";
            $xml .= '    <changefreq>'.$url['changefreq']."</changefreq>\n";
            $xml .= '    <priority>'.$url['priority']."</priority>\n";
            $xml .= "  </url>\n";
        }

        $xml .= "</urlset>\n";

        return $xml;
    }
}
