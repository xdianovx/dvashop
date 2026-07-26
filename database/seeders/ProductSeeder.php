<?php

namespace Database\Seeders;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Enums\StockStatus;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionTemplate;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use App\Models\VehicleGeneration;
use App\Services\Media\ProductGalleryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductSeeder extends Seeder
{
    public function run(ProductGalleryService $gallery): void
    {
        DB::transaction(function () use ($gallery): void {
            $partType = PartType::query()
                ->with('productCategory')
                ->where('full_slug', 'porog')
                ->firstOrFail();

            $category = $partType->productCategory
                ?? ProductCategory::query()
                    ->where('full_slug', 'kuzovnye-detali/remontnye-elementy-kuzova/porogi')
                    ->firstOrFail();
            $genericCategory = ProductCategory::query()->where('full_slug', 'kuzovnye-detali')->firstOrFail();
            $template = ProductOptionTemplate::query()->where('slug', 'default_auto_part')->first();
            $generations = $this->demoGenerations();

            $simple = $this->persistProduct('demo-porog-without-variants', [
                'product_category_id' => $category->getKey(),
                'product_type' => ProductType::AutoPart,
                'part_type_id' => $partType->getKey(),
                'product_option_template_id' => $template?->getKey(),
                'title' => 'Демо порог без вариантов',
                'sku' => 'DEMO-POROG-NO-VARIANTS',
                'price' => 8900,
                'old_price' => 9400,
                'stock_status' => StockStatus::InStock,
                'position' => 10,
            ]);
            $simple->fitments()->delete();
            $this->syncTechnicalVariant($simple, 'DEMO-POROG-NO-VARIANTS-DEFAULT', 8900, 9400);
            $gallery->ensureDefaultImage($simple->refresh());

            $fitmentProduct = $this->persistProduct('demo-porogi-toyota-camry', [
                'product_category_id' => $category->getKey(),
                'product_type' => ProductType::AutoPart,
                'part_type_id' => $partType->getKey(),
                'product_option_template_id' => $template?->getKey(),
                'title' => 'Демо пороги с применяемостью',
                'sku' => 'DEMO-POROGI-CAMRY',
                'short_description' => 'Демонстрационный товар с несколькими применяемостями и вариантами.',
                'price' => 12500,
                'old_price' => 13200,
                'stock_status' => StockStatus::InStock,
                'position' => 20,
                'is_featured' => true,
            ]);
            $this->syncFitments($fitmentProduct, $generations);
            $this->syncDemoVariants($fitmentProduct);
            $gallery->ensureDefaultImage($fitmentProduct->refresh());

            $generic = $this->persistProduct('demo-universal-body-care-kit', [
                'product_category_id' => $genericCategory->getKey(),
                'product_type' => ProductType::Generic,
                'part_type_id' => null,
                'product_option_template_id' => null,
                'title' => 'Универсальный набор для ухода за кузовом',
                'sku' => 'DEMO-GENERIC-BODY-CARE',
                'price' => 2190,
                'old_price' => null,
                'stock_status' => StockStatus::InStock,
                'position' => 30,
            ]);
            $generic->fitments()->delete();
            $this->syncTechnicalVariant($generic, 'DEMO-GENERIC-BODY-CARE-DEFAULT', 2190);
        });
    }

    /** @param array<string, mixed> $attributes */
    private function persistProduct(string $slug, array $attributes): Product
    {
        return Product::query()->updateOrCreate(
            ['slug' => $slug],
            [
                ...$attributes,
                'status' => ProductStatus::Active,
                'is_featured' => (bool) ($attributes['is_featured'] ?? false),
            ],
        );
    }

    /** @return array<int, VehicleGeneration> */
    private function demoGenerations(): array
    {
        $generations = VehicleGeneration::query()
            ->whereIn('norm_key', ['i', 'ng'])
            ->whereHas('model', fn ($models) => $models
                ->where('norm_key', 'vesta')
                ->whereHas('make', fn ($makes) => $makes->where('norm_key', 'lada')))
            ->get()
            ->keyBy('norm_key');

        return [
            $generations->get('i') ?? throw new \RuntimeException('Не найдено поколение Lada Vesta I.'),
            $generations->get('ng') ?? throw new \RuntimeException('Не найдено поколение Lada Vesta NG.'),
        ];
    }

    /** @param array<int, VehicleGeneration> $generations */
    private function syncFitments(Product $product, array $generations): void
    {
        $generationIds = collect($generations)->map->getKey()->all();

        $product->fitments()->whereNotIn('vehicle_generation_id', $generationIds)->delete();

        foreach ($generations as $index => $generation) {
            $product->fitments()->updateOrCreate(
                ['vehicle_generation_id' => $generation->getKey()],
                [
                    'note' => $index === 0 ? 'Основная применяемость' : 'Дополнительная применяемость',
                    'is_primary' => $index === 0,
                ],
            );
        }
    }

    private function syncDemoVariants(Product $product): void
    {
        $definitions = [
            'DEMO-POROGI-CAMRY-BASE' => [
                'title' => 'Полный профиль',
                'price' => 12500,
                'stock_quantity' => 6,
                'is_default' => true,
                'options' => ['profile' => 'full', 'position' => 'both', 'material' => 'galvanized', 'thickness' => '1mm'],
            ],
            'DEMO-POROGI-CAMRY-LOWER' => [
                'title' => 'Нижняя часть',
                'price' => 9900,
                'stock_quantity' => 3,
                'is_default' => false,
                'options' => ['profile' => 'lower', 'position' => 'both', 'material' => 'galvanized', 'thickness' => '1mm'],
            ],
        ];

        $product->variants()
            ->whereNotIn('sku', array_keys($definitions))
            ->get()
            ->each->delete();

        foreach ($definitions as $sku => $definition) {
            $variant = ProductVariant::query()->updateOrCreate(
                ['sku' => $sku],
                [
                    'product_id' => $product->getKey(),
                    'title' => $definition['title'],
                    'price' => $definition['price'],
                    'old_price' => null,
                    'stock_quantity' => $definition['stock_quantity'],
                    'stock_status' => StockStatus::InStock,
                    'is_default' => $definition['is_default'],
                    'is_active' => true,
                ],
            );

            $variant->variantOptionValues()->delete();

            foreach ($definition['options'] as $groupCode => $valueCode) {
                $group = ProductOptionGroup::query()->where('code', $groupCode)->firstOrFail();
                $value = ProductOptionValue::query()
                    ->where('product_option_group_id', $group->getKey())
                    ->where('code', $valueCode)
                    ->firstOrFail();

                $variant->variantOptionValues()->create([
                    'product_option_group_id' => $group->getKey(),
                    'product_option_value_id' => $value->getKey(),
                ]);
            }

            $variant->syncOptionsSnapshotFromValues();
        }
    }

    private function syncTechnicalVariant(Product $product, string $sku, int $price, ?int $oldPrice = null): void
    {
        $product->variants()
            ->where(fn ($variants) => $variants->whereNull('sku')->orWhere('sku', '!=', $sku))
            ->get()
            ->each->delete();

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
    }
}
