<?php

namespace Database\Seeders;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Enums\StockStatus;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\Media\ProductGalleryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

// Local-only fixture data: fills the vehicle tree and the catalog so the
// storefront selects, listings and product pages have something to show.
class DemoDataSeeder extends Seeder
{
    /** @var array<string, array<string, array<int, string>>> */
    private const CATALOG = [
        'Lada' => [
            'Vesta' => ['I 2015–2022', 'NG 2022–н.в.'],
            'Granta' => ['I 2011–2018', 'FL 2018–н.в.'],
            'Largus' => ['I 2012–н.в.'],
            'Niva' => ['Legend 1977–н.в.'],
        ],
        'Toyota' => [
            'Camry' => ['XV50 2011–2017', 'XV70 2017–н.в.'],
            'Corolla' => ['E170 2012–2018', 'E210 2018–н.в.'],
            'RAV4' => ['XA40 2012–2018', 'XA50 2018–н.в.'],
        ],
        'Volkswagen' => [
            'Polo' => ['V 2009–2020', 'VI 2020–н.в.'],
            'Passat' => ['B7 2010–2015', 'B8 2015–н.в.'],
            'Tiguan' => ['I 2007–2016', 'II 2016–н.в.'],
        ],
        'Kia' => [
            'Rio' => ['III 2011–2017', 'IV 2017–н.в.'],
            'Ceed' => ['II 2012–2018', 'III 2018–н.в.'],
            'Sportage' => ['III 2010–2016', 'IV 2016–2021'],
        ],
        'Hyundai' => [
            'Solaris' => ['I 2010–2017', 'II 2017–2022'],
            'Creta' => ['I 2016–2021', 'II 2021–н.в.'],
            'Tucson' => ['III 2015–2020'],
        ],
        'Renault' => [
            'Logan' => ['I 2004–2015', 'II 2014–н.в.'],
            'Duster' => ['I 2010–2021', 'II 2021–н.в.'],
            'Sandero' => ['II 2014–н.в.'],
        ],
        'Ford' => [
            'Focus' => ['II 2004–2011', 'III 2011–2019'],
            'Transit' => ['VII 2013–н.в.'],
        ],
        'Chevrolet' => [
            'Lacetti' => ['I 2004–2013'],
            'Niva' => ['I 2002–2020'],
            'Cruze' => ['I 2009–2016'],
        ],
        'Nissan' => [
            'Qashqai' => ['J11 2013–2022'],
            'X-Trail' => ['T31 2007–2014', 'T32 2013–н.в.'],
            'Almera' => ['G15 2012–2018'],
        ],
        'Skoda' => [
            'Octavia' => ['A5 2004–2013', 'A7 2013–2020'],
            'Rapid' => ['I 2012–2020', 'II 2020–н.в.'],
        ],
        'Mercedes-Benz' => [
            'Sprinter' => ['W906 2006–2018', 'W907 2018–н.в.'],
            'Vito' => ['W639 2003–2014'],
        ],
        'BMW' => [
            '3 series' => ['E90 2005–2012', 'F30 2011–2019'],
            'X5' => ['E70 2006–2013'],
        ],
    ];

    /** @var array<int, array{part: string, title: string, price: int, old_price: int|null}> */
    private const PARTS = [
        ['part' => 'porog', 'title' => 'Пороги', 'price' => 4900, 'old_price' => 5600],
        ['part' => 'arka/zadniaia', 'title' => 'Задние арки', 'price' => 3800, 'old_price' => null],
        ['part' => 'arka/peredniaia', 'title' => 'Передние арки', 'price' => 3500, 'old_price' => 4100],
    ];

    public function run(ProductGalleryService $gallery): void
    {
        $makePosition = 0;

        foreach (self::CATALOG as $makeTitle => $models) {
            $makePosition += 10;
            $make = $this->makeFor($makeTitle, $makePosition);
            $modelPosition = 0;

            foreach ($models as $modelTitle => $generations) {
                $modelPosition += 10;
                $model = $this->modelFor($make, $modelTitle, $modelPosition);
                $generationPosition = 0;
                $created = [];

                foreach ($generations as $generationLabel) {
                    $generationPosition += 10;
                    $created[] = $this->generationFor($model, $generationLabel, $generationPosition);
                }

                $this->productsFor($make, $model, $created, $gallery);
            }
        }
    }

    private function makeFor(string $title, int $position): VehicleMake
    {
        $slug = Str::slug($title);

        return VehicleMake::query()->updateOrCreate(
            ['norm_key' => $slug],
            ['title' => $title, 'slug' => $slug, 'position' => $position, 'is_active' => true],
        );
    }

    private function modelFor(VehicleMake $make, string $title, int $position): VehicleModel
    {
        $slug = Str::slug($title);

        return VehicleModel::query()->updateOrCreate(
            ['vehicle_make_id' => $make->getKey(), 'norm_key' => $slug],
            ['title' => $title, 'slug' => $slug, 'position' => $position, 'is_active' => true],
        );
    }

    // "XV50 2011–2017" carries the generation name and its years in one string.
    private function generationFor(VehicleModel $model, string $label, int $position): VehicleGeneration
    {
        [$title, $years] = $this->splitGenerationLabel($label);
        $slug = Str::slug($title);

        return VehicleGeneration::query()->updateOrCreate(
            ['vehicle_model_id' => $model->getKey(), 'norm_key' => $slug],
            [
                'title' => $title,
                'slug' => $slug,
                'years_label' => $years,
                'body' => 'sedan',
                'position' => $position,
                'is_active' => true,
            ],
        );
    }

    /** @return array{0: string, 1: string} */
    private function splitGenerationLabel(string $label): array
    {
        $parts = preg_split('/\s+(?=\d{4})/u', $label, 2);

        return [trim($parts[0]), trim($parts[1] ?? '')];
    }

    /** @param array<int, VehicleGeneration> $generations */
    private function productsFor(VehicleMake $make, VehicleModel $model, array $generations, ProductGalleryService $gallery): void
    {
        foreach (self::PARTS as $index => $definition) {
            $partType = PartType::query()->where('full_slug', $definition['part'])->first();

            if (! $partType || $partType->product_category_id === null) {
                continue;
            }

            $slug = Str::slug("{$definition['title']} {$make->title} {$model->title}");
            $sku = Str::upper(Str::slug("DEMO {$make->slug} {$model->slug} {$partType->full_slug}"));

            $product = Product::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'product_category_id' => $partType->product_category_id,
                    'product_type' => ProductType::AutoPart,
                    'part_type_id' => $partType->getKey(),
                    'title' => "{$definition['title']} {$make->title} {$model->title}",
                    'sku' => $sku,
                    'short_description' => "Ремонтные {$definition['title']} для {$make->title} {$model->title}. Оцинкованная сталь 1 мм.",
                    'price' => $definition['price'],
                    'old_price' => $definition['old_price'],
                    'stock_status' => StockStatus::InStock,
                    'status' => ProductStatus::Active,
                    'is_featured' => $index === 0,
                    'position' => ($index + 1) * 10,
                ],
            );

            $this->syncFitments($product, $generations);
            $this->syncTechnicalVariant($product, "{$sku}-DEFAULT", $definition['price'], $definition['old_price']);
            $gallery->ensureDefaultImage($product->refresh());
        }
    }

    /** @param array<int, VehicleGeneration> $generations */
    private function syncFitments(Product $product, array $generations): void
    {
        $generationIds = collect($generations)->map->getKey()->all();
        $product->fitments()->whereNotIn('vehicle_generation_id', $generationIds)->delete();

        foreach ($generations as $index => $generation) {
            $product->fitments()->updateOrCreate(
                ['vehicle_generation_id' => $generation->getKey()],
                ['note' => null, 'is_primary' => $index === 0],
            );
        }
    }

    private function syncTechnicalVariant(Product $product, string $sku, int $price, ?int $oldPrice): void
    {
        $variant = ProductVariant::query()->updateOrCreate(
            ['sku' => $sku],
            [
                'product_id' => $product->getKey(),
                'title' => 'Основной',
                'options' => ProductVariant::technicalOptions(),
                'price' => $price,
                'old_price' => $oldPrice,
                'stock_quantity' => null,
                'stock_status' => StockStatus::InStock,
                'is_default' => true,
                'is_active' => true,
            ],
        );

        $variant->variantOptionValues()->delete();

        $product->variants()
            ->whereKeyNot($variant->getKey())
            ->get()
            ->each->delete();
    }
}
