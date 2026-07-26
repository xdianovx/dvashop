<?php

use App\Enums\ProductType;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VehicleGeneration;
use App\Services\CartManager;
use Database\Seeders\PartTypeSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\ProductOptionSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\VehicleCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

test('Stage 6 ProductSeeder creates a compact idempotent product matrix', function () {
    $seeders = [
        ProductCatalogSeeder::class,
        PartTypeSeeder::class,
        ProductOptionSeeder::class,
        VehicleCatalogSeeder::class,
        ProductSeeder::class,
    ];

    $this->seed($seeders);

    $simple = Product::query()->where('slug', 'demo-porog-without-variants')->firstOrFail();
    $fitmentProduct = Product::query()->where('slug', 'demo-porogi-toyota-camry')->firstOrFail();
    $generic = Product::query()->where('slug', 'demo-universal-body-care-kit')->firstOrFail();
    $ids = Product::query()->whereIn('id', [$simple->id, $fitmentProduct->id, $generic->id])->pluck('id', 'slug')->all();
    $publicProducts = Product::query()
        ->active()
        ->whereIn('id', array_values($ids))
        ->whereHas('defaultVariant', fn ($variants) => $variants->where('is_active', true))
        ->with('defaultVariant')
        ->get();

    expect($simple->product_type)->toBe(ProductType::AutoPart)
        ->and($simple->part_type_id)->not->toBeNull()
        ->and($simple->variants()->count())->toBe(1)
        ->and($simple->defaultVariant()->where('is_active', true)->exists())->toBeTrue()
        ->and($simple->defaultVariant()->firstOrFail()->isTechnical())->toBeTrue()
        ->and($simple->defaultVariant()->firstOrFail()->optionValues()->count())->toBe(0)
        ->and($simple->price)->toBe('8900.00')
        ->and($simple->images()->exists())->toBeTrue()
        ->and($fitmentProduct->product_type)->toBe(ProductType::AutoPart)
        ->and($fitmentProduct->fitments()->count())->toBe(2)
        ->and($fitmentProduct->variants()->count())->toBe(2)
        ->and($fitmentProduct->variants()->distinct()->count('sku'))->toBe(2)
        ->and($fitmentProduct->variants()->where('is_default', true)->count())->toBe(1)
        ->and($fitmentProduct->variants()->with('optionValues')->get()->every(fn ($variant): bool => $variant->optionValues->count() === 4))->toBeTrue()
        ->and($generic->product_type)->toBe(ProductType::Generic)
        ->and($generic->part_type_id)->toBeNull()
        ->and($generic->product_option_template_id)->toBeNull()
        ->and($generic->fitments()->count())->toBe(0)
        ->and($generic->variants()->count())->toBe(1)
        ->and($generic->defaultVariant()->where('is_active', true)->exists())->toBeTrue()
        ->and($generic->defaultVariant()->firstOrFail()->isTechnical())->toBeTrue()
        ->and($generic->category)->not->toBeNull()
        ->and($publicProducts)->toHaveCount(3)
        ->and($publicProducts->every(fn (Product $product): bool => (float) $product->defaultVariant->price >= 0))->toBeTrue()
        ->and(VehicleGeneration::query()->where('norm_key', 'stage-6-demo-second')->exists())->toBeFalse()
        ->and(Product::query()->whereIn('id', array_values($ids))->pluck('sku')->filter()->duplicates()->isEmpty())->toBeTrue();

    $cart = Cart::factory()->create();
    $request = Request::create('/', 'GET', [], [CartManager::COOKIE_NAME => $cart->token]);
    foreach ($publicProducts as $product) {
        $item = app(CartManager::class)->addItem($request, $product->defaultVariant->getKey());
        expect($item->product_variant_id)->toBe($product->defaultVariant->getKey());
    }

    ProductVariant::factory()->forProduct($simple)->default()->create([
        'sku' => 'DEMO-EXTRA-VARIANT',
        'price' => 1,
    ]);

    $this->seed(ProductSeeder::class);

    expect(Product::query()->whereIn('slug', array_keys($ids))->count())->toBe(3)
        ->and(Product::query()->whereIn('slug', array_keys($ids))->pluck('id', 'slug')->all())->toBe($ids)
        ->and($simple->variants()->count())->toBe(1)
        ->and($simple->variants()->where('is_default', true)->count())->toBe(1)
        ->and($simple->variants()->where('sku', 'DEMO-EXTRA-VARIANT')->exists())->toBeFalse()
        ->and($fitmentProduct->fitments()->count())->toBe(2)
        ->and($fitmentProduct->variants()->count())->toBe(2)
        ->and($generic->variants()->count())->toBe(1);
});
