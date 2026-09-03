<?php

use App\Enums\DeliveryMethod;
use App\Enums\DeliveryPriceMode;
use App\Enums\PaymentMethod;
use App\Enums\StockStatus;
use App\Models\Cart;
use App\Models\DeliveryMethodSetting;
use App\Models\Order;
use App\Models\PartType;
use App\Models\PaymentMethodSetting;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductCharacteristic;
use App\Models\ProductFitment;
use App\Models\ProductImage;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use App\Models\ProductVariantOptionValue;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\CartManager;
use App\ViewModels\ProductCardViewModel;
use Database\Seeders\CheckoutMethodSettingsSeeder;
use Database\Seeders\HomepageContentSeeder;
use Database\Seeders\ShopSettingsSeeder;
use Database\Seeders\StaticPageContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function commerceCartRequest(Cart $cart): Request
{
    return Request::create('/cart', 'GET', [], [CartManager::COOKIE_NAME => $cart->token]);
}

test('catalog routes enforce the active vehicle hierarchy and keep their page templates', function (): void {
    $make = VehicleMake::factory()->create(['title' => 'Volga', 'slug' => 'volga']);
    $model = VehicleModel::factory()->forMake($make)->create(['title' => 'Nova', 'slug' => 'nova']);
    $generation = VehicleGeneration::factory()->forVehicleModel($model)->create(['title' => 'First', 'slug' => 'first']);
    $publicProduct = Product::factory()->withDefaultVariant()->create(['title' => 'Volga public product']);
    ProductFitment::factory()->forProduct($publicProduct)->forVehicleGeneration($generation)->create();
    $otherMake = VehicleMake::factory()->create(['slug' => 'other']);

    $this->get(route('catalog.make', $make->slug))->assertOk()->assertSee('brand-page', false)->assertSee('Nova');
    $this->get(route('catalog.model', [$make->slug, $model->slug]))->assertOk()->assertSee('model-page', false)->assertSee('First');
    $this->get(route('catalog.generation', [$make->slug, $model->slug, $generation->slug]))->assertOk()->assertSee('product-head', false);
    $this->get(route('catalog.model', [$otherMake->slug, $model->slug]))->assertNotFound();

    $model->update(['is_active' => false]);
    $this->get(route('catalog.model', [$make->slug, $model->slug]))->assertNotFound();
});

test('catalog filters active products by category and part type full slugs', function (): void {
    $category = ProductCategory::factory()->create(['title' => 'Пороги', 'slug' => 'sills']);
    $partType = PartType::factory()->forCategory($category)->create(['title' => 'Наружные пороги']);
    $matching = Product::factory()->forCategory($category)->forPartType($partType)->withDefaultVariant()->create(['title' => 'Подходящий товар']);
    Product::factory()->withDefaultVariant()->create(['title' => 'Другой товар']);
    Product::factory()->forCategory($category)->forPartType($partType)->draft()->withDefaultVariant()->create(['title' => 'Скрытый товар']);

    $this->get(route('catalog.index', ['category' => $category->full_slug, 'part_type' => $partType->full_slug]))
        ->assertOk()
        ->assertSee($matching->title)
        ->assertDontSee('Другой товар')
        ->assertDontSee('Скрытый товар');
});

test('public catalog and product page hide products with inactive or deleted relations', function (): void {
    $inactiveCategory = ProductCategory::factory()->create(['title' => 'Inactive relation category']);
    $inactiveCategoryProduct = Product::factory()->forCategory($inactiveCategory)->withDefaultVariant()->create(['title' => 'Inactive category product']);
    $deletedCategory = ProductCategory::factory()->create(['title' => 'Deleted relation category']);
    $deletedCategoryProduct = Product::factory()->forCategory($deletedCategory)->withDefaultVariant()->create(['title' => 'Deleted category product']);
    $activeCategory = ProductCategory::factory()->create(['title' => 'Active relation category']);
    $inactivePartType = PartType::factory()->forCategory($activeCategory)->create(['title' => 'Inactive relation part type']);
    $inactivePartTypeProduct = Product::factory()->forCategory($activeCategory)->forPartType($inactivePartType)->withDefaultVariant()->create(['title' => 'Inactive part type product']);
    $deletedPartType = PartType::factory()->forCategory($activeCategory)->create(['title' => 'Deleted relation part type']);
    $deletedPartTypeProduct = Product::factory()->forCategory($activeCategory)->forPartType($deletedPartType)->withDefaultVariant()->create(['title' => 'Deleted part type product']);

    DB::table('product_categories')->where('id', $inactiveCategory->getKey())->update(['is_active' => false]);
    DB::table('product_categories')->where('id', $deletedCategory->getKey())->update(['deleted_at' => now()]);
    DB::table('part_types')->where('id', $inactivePartType->getKey())->update(['is_active' => false]);
    DB::table('part_types')->where('id', $deletedPartType->getKey())->update(['deleted_at' => now()]);

    $this->get(route('catalog.index', ['q' => 'relation product']))
        ->assertOk()
        ->assertDontSee($inactiveCategoryProduct->title)
        ->assertDontSee($deletedCategoryProduct->title)
        ->assertDontSee($inactivePartTypeProduct->title)
        ->assertDontSee($deletedPartTypeProduct->title);

    foreach ([$inactiveCategoryProduct, $deletedCategoryProduct, $inactivePartTypeProduct, $deletedPartTypeProduct] as $product) {
        $this->get(route('products.show', $product->slug))->assertNotFound();
    }

    $cart = Cart::factory()->create();
    foreach ([$inactiveCategoryProduct, $inactivePartTypeProduct] as $product) {
        $variantId = (int) ProductVariant::query()->where('product_id', $product->getKey())->firstOrFail()->getKey();
        $this->withCookie(CartManager::COOKIE_NAME, $cart->token)
            ->post(route('cart.items.store'), ['product_variant_id' => $variantId, 'quantity' => 1])
            ->assertSessionHasErrors('product_variant_id');
    }
    expect($cart->items()->count())->toBe(0);
});

test('public product uses an active alternative when its default variant is not public', function (): void {
    $inactiveDefaultProduct = Product::factory()->create(['title' => 'Inactive default public alternative']);
    $inactiveDefault = ProductVariant::factory()->forProduct($inactiveDefaultProduct)->default()->create(['sku' => 'HIDDEN-DEFAULT']);
    $activeAlternative = ProductVariant::factory()->forProduct($inactiveDefaultProduct)->create([
        'sku' => 'PUBLIC-ALTERNATIVE',
        'stock_quantity' => null,
    ]);
    DB::table('product_variants')->where('id', $inactiveDefault->getKey())->update(['is_active' => false]);

    $inactiveValueProduct = Product::factory()->create(['title' => 'Inactive value public alternative']);
    $inactiveValueDefault = ProductVariant::factory()->forProduct($inactiveValueProduct)->default()->create(['sku' => 'HIDDEN-VALUE-DEFAULT']);
    $activeValueAlternative = ProductVariant::factory()->forProduct($inactiveValueProduct)->create([
        'sku' => 'PUBLIC-VALUE-ALTERNATIVE',
        'stock_quantity' => null,
    ]);
    $group = ProductOptionGroup::factory()->create();
    $inactiveValue = ProductOptionValue::factory()->forGroup($group)->create();
    ProductVariantOptionValue::factory()->create([
        'product_variant_id' => $inactiveValueDefault->getKey(),
        'product_option_group_id' => $group->getKey(),
        'product_option_value_id' => $inactiveValue->getKey(),
    ]);
    DB::table('product_option_values')->where('id', $inactiveValue->getKey())->update(['is_active' => false]);

    foreach ([
        [$inactiveDefaultProduct, $activeAlternative],
        [$inactiveValueProduct, $activeValueAlternative],
    ] as [$product, $expectedVariant]) {
        $catalog = $this->get(route('catalog.index', ['q' => $product->title]))->assertOk()->assertSee($product->title);
        $card = collect($catalog->viewData('products')->items())->firstWhere('id', $product->getKey());
        expect($card)->toBeInstanceOf(ProductCardViewModel::class)
            ->and($card->sku)->toBe($expectedVariant->sku)
            ->and($card->variantId)->toBe($expectedVariant->getKey());

        $this->get(route('products.show', $product->slug))
            ->assertOk()
            ->assertViewHas('variant', fn (ProductVariant $variant): bool => $variant->is($expectedVariant));
    }
});

test('product card quick add requires exactly one purchasable public variant', function (): void {
    $singleInStock = ProductVariant::factory()->default()->create([
        'stock_status' => StockStatus::InStock,
        'stock_quantity' => 3,
    ]);
    $singleUnlimited = ProductVariant::factory()->default()->create([
        'stock_status' => StockStatus::InStock,
        'stock_quantity' => null,
    ]);
    $singlePreOrder = ProductVariant::factory()->default()->create([
        'stock_status' => StockStatus::PreOrder,
        'stock_quantity' => null,
    ]);
    $singleOutOfStock = ProductVariant::factory()->default()->create([
        'stock_status' => StockStatus::OutOfStock,
        'stock_quantity' => 0,
    ]);
    $singleEmptyStock = ProductVariant::factory()->default()->create([
        'stock_status' => StockStatus::InStock,
        'stock_quantity' => 0,
    ]);

    $multiProduct = Product::factory()->create();
    ProductVariant::factory()->forProduct($multiProduct)->default()->create(['stock_quantity' => 5]);
    ProductVariant::factory()->forProduct($multiProduct)->create(['stock_quantity' => 5]);

    $alternativeProduct = Product::factory()->create();
    $inactiveDefault = ProductVariant::factory()->forProduct($alternativeProduct)->default()->create(['stock_quantity' => 5]);
    $publicAlternative = ProductVariant::factory()->forProduct($alternativeProduct)->create(['stock_quantity' => 5]);
    DB::table('product_variants')->where('id', $inactiveDefault->getKey())->update(['is_active' => false]);

    foreach ([$singleInStock, $singleUnlimited, $singlePreOrder] as $purchasable) {
        expect(ProductCardViewModel::fromProduct($purchasable->product)->variantId)->toBe($purchasable->getKey());
    }
    foreach ([$singleOutOfStock, $singleEmptyStock] as $unavailable) {
        expect(ProductCardViewModel::fromProduct($unavailable->product)->variantId)->toBeNull();
    }

    expect(ProductCardViewModel::fromProduct($multiProduct)->variantId)->toBeNull()
        ->and(ProductCardViewModel::fromProduct($alternativeProduct)->variantId)->toBe($publicAlternative->getKey());
});

test('catalog category filter includes only active descendants from the selected slug path', function (): void {
    $root = ProductCategory::factory()->create(['title' => 'Root category', 'slug' => 'root-category']);
    $activeChild = ProductCategory::factory()->create(['parent_id' => $root->getKey(), 'title' => 'Active child', 'slug' => 'active-child']);
    $inactiveChild = ProductCategory::factory()->create(['parent_id' => $root->getKey(), 'title' => 'Inactive child', 'slug' => 'inactive-child']);
    $rootProduct = Product::factory()->forCategory($root)->withDefaultVariant()->create(['title' => 'Root branch product']);
    $activeChildProduct = Product::factory()->forCategory($activeChild)->withDefaultVariant()->create(['title' => 'Active branch product']);
    $inactiveChildProduct = Product::factory()->forCategory($inactiveChild)->withDefaultVariant()->create(['title' => 'Inactive branch product']);
    DB::table('product_categories')->where('id', $inactiveChild->getKey())->update(['is_active' => false]);

    $this->get(route('catalog.index', ['category' => $root->full_slug]))
        ->assertOk()
        ->assertSee($rootProduct->title)
        ->assertSee($activeChildProduct->title)
        ->assertDontSee($inactiveChildProduct->title);
});

test('product page renders only visible media and submits only variant and quantity to cart', function (): void {
    $product = Product::factory()->withDefaultVariant()->create(['title' => 'Реальный порог']);
    ProductImage::factory()->forProduct($product)->main()->create(['path' => 'https://cdn.example.test/visible.jpg', 'is_visible' => true]);
    ProductImage::factory()->forProduct($product)->create(['path' => 'https://cdn.example.test/hidden.jpg', 'is_visible' => false]);

    $this->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee('Реальный порог')
        ->assertSee('https://cdn.example.test/visible.jpg', false)
        ->assertDontSee('https://cdn.example.test/hidden.jpg', false)
        ->assertSee('data-gallery-main', false)
        ->assertDontSee('data-gallery-thumbs', false)
        ->assertSee('name="product_variant_id"', false)
        ->assertSee('name="quantity"', false)
        ->assertDontSee('name="price"', false);
});

test('product gallery places the visible main image first without duplication', function (): void {
    $product = Product::factory()->withDefaultVariant()->create();
    $firstByPosition = ProductImage::factory()->forProduct($product)->create([
        'path' => 'https://cdn.example.test/gallery-a.jpg',
        'position' => 0,
        'is_main' => false,
        'is_visible' => true,
    ]);
    $main = ProductImage::factory()->forProduct($product)->main()->create([
        'path' => 'https://cdn.example.test/gallery-b-main.jpg',
        'position' => 1,
        'is_visible' => true,
    ]);

    $response = $this->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee('data-gallery-main', false)
        ->assertSee('data-gallery-thumbs', false);
    $galleryUrls = $response->viewData('gallery')->pluck('url')->all();

    expect($galleryUrls)->toBe([
        'https://cdn.example.test/gallery-b-main.jpg',
        'https://cdn.example.test/gallery-a.jpg',
    ])->and(collect($galleryUrls)->filter(fn (string $url): bool => $url === 'https://cdn.example.test/gallery-b-main.jpg')->count())->toBe(1)
        ->and(collect($galleryUrls)->filter(fn (string $url): bool => $url === 'https://cdn.example.test/gallery-a.jpg')->count())->toBe(1)
        ->and($main->exists)->toBeTrue()
        ->and($firstByPosition->exists)->toBeTrue();
});

test('product gallery falls back to real part type and generic category default images', function (): void {
    $category = ProductCategory::factory()->create(['title' => 'Пороги', 'slug' => 'porog']);
    $partType = PartType::factory()->forCategory($category)->create([
        'title' => 'Default image part type',
        'default_image_key' => 'porog',
    ]);
    $partTypeProduct = Product::factory()->forCategory($category)->forPartType($partType)->withDefaultVariant()->create();
    $genericProduct = Product::factory()->forCategory($category)->generic()->withDefaultVariant()->create();

    $this->get(route('products.show', $partTypeProduct->slug))
        ->assertOk()
        ->assertSee('/img/products_default/porog.png', false)
        ->assertSee('data-gallery-main', false)
        ->assertDontSee('data-gallery-thumbs', false)
        ->assertDontSee('/img/placeholders/image.svg', false);
    $this->get(route('products.show', $genericProduct->slug))
        ->assertOk()
        ->assertSee('/img/products_default/porog.png', false)
        ->assertDontSee('/img/placeholders/image.svg', false);
});

test('cart HTTP flow uses snapshots ownership forms and stock status', function (): void {
    $variant = ProductVariant::factory()->default()->create(['stock_status' => StockStatus::InStock, 'stock_quantity' => 2]);
    $cart = Cart::factory()->create();

    $this->withCookie(CartManager::COOKIE_NAME, $cart->token)
        ->post(route('cart.items.store'), ['product_variant_id' => $variant->getKey(), 'quantity' => 2])
        ->assertRedirect(route('cart.show'));

    $item = $cart->items()->firstOrFail();
    $this->withCookie(CartManager::COOKIE_NAME, $cart->token)->get(route('cart.show'))
        ->assertOk()
        ->assertSee($item->title_snapshot)
        ->assertSee(route('cart.items.update', $item), false)
        ->assertSee(route('cart.items.destroy', $item), false);

    $this->withCookie(CartManager::COOKIE_NAME, $cart->token)
        ->patch(route('cart.items.update', $item), ['quantity' => 3])
        ->assertSessionHasErrors('quantity');
    expect($item->refresh()->quantity)->toBe(2);
});

test('checkout without address uses active settings delivery price and protects immutable success snapshots', function (): void {
    $delivery = DeliveryMethodSetting::factory()->create(['code' => DeliveryMethod::Courier, 'title' => 'Курьер', 'base_price' => 490, 'price_mode' => DeliveryPriceMode::Fixed, 'is_active' => true]);
    $payment = PaymentMethodSetting::factory()->create(['code' => PaymentMethod::Card, 'title' => 'Карта', 'is_active' => true]);
    $variant = ProductVariant::factory()->default()->create(['price' => 1500, 'stock_quantity' => null]);
    $cart = Cart::factory()->create();
    app(CartManager::class)->addItem(commerceCartRequest($cart), $variant->getKey(), 2);

    $response = $this->withCookie(CartManager::COOKIE_NAME, $cart->token)->post(route('checkout.store'), [
        'customer_name' => 'Иван Петров',
        'customer_phone' => '+79990000000',
        'customer_email' => 'ivan@example.test',
        'customer_city' => 'Москва',
        'delivery_method' => $delivery->code->value,
        'payment_method' => $payment->code->value,
        'agree_terms' => '1',
    ]);

    $response->assertRedirect();
    $order = Order::query()->firstOrFail();
    expect($order->customer_address)->toBeNull()
        ->and($order->delivery_address)->toBeNull()
        ->and($order->subtotal)->toBe('3000.00')
        ->and($order->discount_total)->toBe('0.00')
        ->and($order->delivery_price)->toBe('490.00')
        ->and($order->total)->toBe('3490.00');
    $this->get($response->headers->get('Location'))->assertOk()->assertSee($order->number)->assertSee('Иван Петров');

    $foreignOrder = Order::factory()->create();
    $this->get(route('checkout.success', ['order' => $foreignOrder->number, 'token' => request()->query('token')]))->assertNotFound();
});

test('homepage vehicle search loads only active models for the selected active make', function (): void {
    $this->seed([ShopSettingsSeeder::class, StaticPageContentSeeder::class, CheckoutMethodSettingsSeeder::class, HomepageContentSeeder::class]);
    $make = VehicleMake::factory()->create(['title' => 'Active Make', 'slug' => 'active-make']);
    $activeModel = VehicleModel::factory()->forMake($make)->create(['title' => 'Active Model', 'slug' => 'active-model']);
    $activeGeneration = VehicleGeneration::factory()->forVehicleModel($activeModel)->create(['title' => 'Active Generation']);
    $activeProduct = Product::factory()->withDefaultVariant()->create(['title' => 'Active vehicle product']);
    ProductFitment::factory()->forProduct($activeProduct)->forVehicleGeneration($activeGeneration)->create();
    VehicleModel::factory()->inactive()->forMake($make)->create(['title' => 'Inactive Model', 'slug' => 'inactive-model']);
    $inactiveMake = VehicleMake::factory()->create(['title' => 'Hidden Make']);
    VehicleModel::factory()->forMake($inactiveMake)->create(['title' => 'Hidden Model']);
    DB::table('vehicle_makes')->where('id', $inactiveMake->getKey())->update(['is_active' => false]);

    $this->get(route('home'))->assertOk()
        ->assertSee('action="'.route('catalog.index').'"', false)
        ->assertDontSee('action="#"', false)
        ->assertSee('select class="search__field-value" name="make"', false)
        ->assertSee('select class="search__field-value" name="model"', false)
        ->assertSee('search__field', false)
        ->assertSee('search__field-label', false)
        ->assertSee('search__divider', false)
        ->assertSee('search__submit', false)
        ->assertSee('Active Make')
        ->assertSee('data-models-url-template', false)
        ->assertSee('data-vehicle-model', false)
        ->assertDontSee('Active Model')
        ->assertDontSee('Inactive Model')
        ->assertDontSee('Hidden Make')
        ->assertDontSee('Hidden Model');

    $this->getJson(route('storefront.vehicle-makes.models', $make->slug))
        ->assertOk()
        ->assertExactJson([
            ['title' => 'Active Model', 'slug' => 'active-model'],
        ])
        ->assertJsonMissing(['id' => 1])
        ->assertJsonMissing(['title' => 'Inactive Model']);

    $this->getJson(route('storefront.vehicle-makes.models', 'unknown-make'))->assertNotFound();
    $this->getJson(route('storefront.vehicle-makes.models', $inactiveMake->slug))->assertNotFound();

    $this->get(route('catalog.index', ['make' => $make->slug, 'model' => $make->slug.':active-model']))
        ->assertRedirect(route('catalog.model', [$make->slug, 'active-model']));
});

test('catalog search filters paginate and preserve the real query string', function (): void {
    $category = ProductCategory::factory()->create(['title' => 'Search category', 'slug' => 'search-category']);
    $partType = PartType::factory()->forCategory($category)->create(['title' => 'Search part type']);
    $otherPartType = PartType::factory()->forCategory($category)->create(['title' => 'Other search part type']);
    $otherCategory = ProductCategory::factory()->create(['title' => 'Other search category']);
    $otherCategoryPartType = PartType::factory()->forCategory($otherCategory)->create(['title' => 'Other category part type']);

    Product::factory()->forCategory($category)->forPartType($partType)->withDefaultVariant()->create(['title' => 'Needle exact product']);
    Product::factory()->forCategory($category)->forPartType($otherPartType)->withDefaultVariant()->create(['title' => 'Needle wrong part type']);
    Product::factory()->forCategory($otherCategory)->forPartType($otherCategoryPartType)->withDefaultVariant()->create(['title' => 'Needle wrong category']);
    Product::factory()->forCategory($category)->forPartType($partType)->withDefaultVariant()->create(['title' => 'Different title']);

    $this->get(route('catalog.index', ['q' => 'Needle']))
        ->assertOk()
        ->assertSee('Needle exact product')
        ->assertSee('Needle wrong part type')
        ->assertSee('Needle wrong category')
        ->assertDontSee('Different title');
    $this->get(route('catalog.index', ['category' => $category->full_slug]))
        ->assertOk()
        ->assertSee('Needle exact product')
        ->assertSee('Needle wrong part type')
        ->assertDontSee('Needle wrong category');
    $this->get(route('catalog.index', ['part_type' => $partType->full_slug]))
        ->assertOk()
        ->assertSee('Needle exact product')
        ->assertDontSee('Needle wrong category')
        ->assertDontSee('Needle wrong part type');
    $this->get(route('catalog.index', [
        'q' => 'Needle',
        'category' => $category->full_slug,
        'part_type' => $partType->full_slug,
    ]))->assertOk()
        ->assertSee('Needle exact product')
        ->assertDontSee('Needle wrong part type')
        ->assertDontSee('Needle wrong category')
        ->assertDontSee('Different title');

    Product::query()->where('title', 'Needle exact product')->delete();
    foreach (range(1, 13) as $index) {
        Product::factory()->forCategory($category)->forPartType($partType)->withDefaultVariant()->create([
            'title' => sprintf('Needle paginated %02d', $index),
            'position' => $index,
        ]);
    }

    $parameters = ['q' => 'Needle', 'category' => $category->full_slug, 'part_type' => $partType->full_slug];
    $pageOne = $this->get(route('catalog.index', $parameters))->assertOk();
    $pageOne->assertSee('Needle paginated 01')->assertDontSee('Needle paginated 13');
    parse_str((string) parse_url($pageOne->viewData('products')->nextPageUrl(), PHP_URL_QUERY), $nextPageQuery);
    expect($nextPageQuery)->toMatchArray([...$parameters, 'page' => '2']);
    $this->get(route('catalog.index', [...$parameters, 'page' => 2]))
        ->assertOk()
        ->assertSee('Needle paginated 13')
        ->assertDontSee('Needle paginated 01');
});

test('catalog hides inactive and deleted vehicle hierarchy rows and rejects crossed routes', function (): void {
    $make = VehicleMake::factory()->create(['slug' => 'visible-make']);
    $model = VehicleModel::factory()->forMake($make)->create(['slug' => 'visible-model']);
    $generation = VehicleGeneration::factory()->forVehicleModel($model)->create(['slug' => 'visible-generation']);
    $otherMake = VehicleMake::factory()->create(['slug' => 'other-make']);
    $otherModel = VehicleModel::factory()->forMake($otherMake)->create(['slug' => 'other-model']);
    $otherGeneration = VehicleGeneration::factory()->forVehicleModel($otherModel)->create(['slug' => 'other-generation']);

    $this->get(route('catalog.model', [$otherMake->slug, $model->slug]))->assertNotFound();
    $this->get(route('catalog.generation', [$make->slug, $model->slug, $otherGeneration->slug]))->assertNotFound();

    DB::table('vehicle_generations')->where('id', $generation->getKey())->update(['is_active' => false]);
    $this->get(route('catalog.generation', [$make->slug, $model->slug, $generation->slug]))->assertNotFound();
    DB::table('vehicle_generations')->where('id', $generation->getKey())->update(['is_active' => true, 'deleted_at' => now()]);
    $this->get(route('catalog.generation', [$make->slug, $model->slug, $generation->slug]))->assertNotFound();

    DB::table('vehicle_models')->where('id', $model->getKey())->update(['is_active' => false]);
    $this->get(route('catalog.model', [$make->slug, $model->slug]))->assertNotFound();
    DB::table('vehicle_makes')->where('id', $make->getKey())->update(['deleted_at' => now()]);
    $this->get(route('catalog.make', $make->slug))->assertNotFound();
});

test('product page exposes real characteristics and server variant matrix without a fitment section', function (): void {
    $make = VehicleMake::factory()->create(['title' => 'Matrix Make']);
    $model = VehicleModel::factory()->forMake($make)->create(['title' => 'Matrix Model']);
    $generation = VehicleGeneration::factory()->forVehicleModel($model)->create(['title' => 'Matrix Generation']);
    $category = ProductCategory::factory()->create(['title' => 'Matrix Category']);
    $partType = PartType::factory()->forCategory($category)->create(['title' => 'Matrix Part Type']);
    $product = Product::factory()->forCategory($category)->forPartType($partType)->create([
        'title' => 'Matrix Product',
        'sku' => 'MATRIX-PRODUCT-SKU',
        'description' => 'Matrix product description',
        'price' => 1000,
    ]);

    ProductImage::factory()->forProduct($product)->main()->create([
        'path' => 'https://cdn.example.test/matrix-product.jpg',
        'is_visible' => true,
    ]);
    ProductCharacteristic::factory()->create(['product_id' => $product->getKey(), 'name' => 'Толщина', 'value' => '1.5', 'unit' => 'мм']);
    ProductCharacteristic::factory()->create(['product_id' => $product->getKey(), 'name' => 'Скрытая характеристика', 'is_visible' => false]);
    ProductFitment::factory()->forProduct($product)->forVehicleGeneration($generation)->primary()->create(['note' => 'Реальная применимость']);

    $profile = ProductOptionGroup::factory()->create(['title' => 'Профиль', 'code' => 'profile', 'position' => 1]);
    $material = ProductOptionGroup::factory()->create(['title' => 'Материал', 'code' => 'material', 'position' => 2]);
    $full = ProductOptionValue::factory()->forGroup($profile)->create(['title' => 'Полный', 'code' => 'full']);
    $lower = ProductOptionValue::factory()->forGroup($profile)->create(['title' => 'Нижняя часть', 'code' => 'lower']);
    $steel = ProductOptionValue::factory()->forGroup($material)->create(['title' => 'Сталь', 'code' => 'steel']);
    $zinc = ProductOptionValue::factory()->forGroup($material)->create(['title' => 'Оцинковка', 'code' => 'zinc']);
    $inactiveValue = ProductOptionValue::factory()->forGroup($material)->create(['title' => 'Скрытый материал', 'code' => 'hidden', 'is_active' => false]);

    $default = ProductVariant::factory()->forProduct($product)->default()->create([
        'sku' => null,
        'price' => 2450,
        'old_price' => 2700,
        'stock_status' => StockStatus::OutOfStock,
        'stock_quantity' => 0,
    ]);
    $inStock = ProductVariant::factory()->forProduct($product)->create([
        'sku' => 'MATRIX-IN-STOCK',
        'price' => 2600,
        'stock_status' => StockStatus::InStock,
        'stock_quantity' => 4,
    ]);
    $preOrder = ProductVariant::factory()->forProduct($product)->create([
        'sku' => 'MATRIX-PREORDER',
        'price' => 2800,
        'stock_status' => StockStatus::PreOrder,
        'stock_quantity' => null,
    ]);
    $inactiveVariant = ProductVariant::factory()->forProduct($product)->inactive()->create(['price' => 9999]);
    $hiddenValueVariant = ProductVariant::factory()->forProduct($product)->create(['price' => 8888]);
    $foreignVariant = ProductVariant::factory()->default()->create(['price' => 7777]);

    foreach ([[$default, $full], [$default, $steel], [$inStock, $lower], [$inStock, $steel], [$preOrder, $full], [$preOrder, $zinc], [$inactiveVariant, $lower], [$inactiveVariant, $zinc], [$hiddenValueVariant, $full], [$hiddenValueVariant, $inactiveValue]] as [$variant, $value]) {
        ProductVariantOptionValue::factory()->create([
            'product_variant_id' => $variant->getKey(),
            'product_option_group_id' => $value->product_option_group_id,
            'product_option_value_id' => $value->getKey(),
        ]);
    }

    $related = Product::factory()->forCategory($category)->withDefaultVariant()->create(['title' => 'Real related product']);
    Product::factory()->withDefaultVariant()->create(['title' => 'Unrelated product']);

    $response = $this->get(route('products.show', $product->slug))->assertOk()
        ->assertSee('MATRIX-PRODUCT-SKU')
        ->assertSee('Matrix product description')
        ->assertSee('Matrix Category')
        ->assertSee('https://cdn.example.test/matrix-product.jpg', false)
        ->assertSee('Толщина')
        ->assertSee('1.5')
        ->assertDontSee('Скрытая характеристика')
        ->assertDontSee('Применимость')
        ->assertDontSee('Реальная применимость')
        ->assertSee('part-option-group', false)
        ->assertSee('part-tabs', false)
        ->assertSee('part-tab', false)
        ->assertSee('part-radios', false)
        ->assertSee('part-radio', false)
        ->assertSee('part-radio__dot', false)
        ->assertSee('part-radio__label', false)
        ->assertSee('Профиль')
        ->assertSee('Материал')
        ->assertSee('Полный')
        ->assertSee('Нижняя часть')
        ->assertSee('Оцинковка')
        ->assertDontSee('Скрытый материал')
        ->assertDontSee('"variant_id":'.$inactiveVariant->getKey(), false)
        ->assertDontSee('"variant_id":'.$foreignVariant->getKey(), false)
        ->assertSee('2 450 руб.')
        ->assertSee('part-buy__stock--out-of-stock', false)
        ->assertSee(StockStatus::OutOfStock->label())
        ->assertSee('data-add-to-cart disabled', false)
        ->assertSee('Real related product')
        ->assertDontSee('Unrelated product')
        ->assertDontSee('part-buy__rating', false)
        ->assertSee('part-gallery__fav', false)
        ->assertSee('data-favorite-product-id="'.$product->getKey().'"', false)
        ->assertSee('aria-pressed="false"', false);

    $matrix = collect($response->viewData('variantMatrix'))->keyBy('variant_id');
    expect($matrix->keys()->sort()->values()->all())->toBe(collect([$default->getKey(), $inStock->getKey(), $preOrder->getKey()])->sort()->values()->all())
        ->and($matrix[$default->getKey()]['sku'])->toBe('MATRIX-PRODUCT-SKU')
        ->and($matrix[$inStock->getKey()]['sku'])->toBe('MATRIX-IN-STOCK')
        ->and($matrix[$preOrder->getKey()]['sku'])->toBe('MATRIX-PREORDER')
        ->and($matrix[$default->getKey()]['price'])->toBe('2450.00')
        ->and($matrix[$default->getKey()]['stock_status'])->toBe(StockStatus::OutOfStock->value)
        ->and($matrix[$inStock->getKey()]['stock_quantity'])->toBe(4)
        ->and($matrix[$preOrder->getKey()]['stock_status'])->toBe(StockStatus::PreOrder->value)
        ->and($response->viewData('availableValues')->pluck('id')->all())->toContain($full->getKey(), $lower->getKey(), $steel->getKey(), $zinc->getKey())
        ->not->toContain($inactiveValue->getKey());

    expect($related->exists)->toBeTrue()
        ->and($product->fitments()->whereKey($product->fitments()->sole()->getKey())->exists())->toBeTrue();
});

test('product description is escaped while preserving line breaks', function (): void {
    $product = Product::factory()->withDefaultVariant()->create([
        'title' => 'Escaped product description',
        'description' => "Первая строка\n<script>alert('unsafe')</script>",
    ]);

    $this->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee('Первая строка<br>', false)
        ->assertSee('&lt;script&gt;alert(&#039;unsafe&#039;)&lt;/script&gt;', false)
        ->assertDontSee("<script>alert('unsafe')</script>", false);

    expect(file_get_contents(resource_path('views/part.blade.php')))
        ->not->toContain('{!!')
        ->not->toContain('nl2br(');
});

test('generation category navigation shares public product and variant availability', function (): void {
    $make = VehicleMake::factory()->create();
    $model = VehicleModel::factory()->forMake($make)->create();
    $generation = VehicleGeneration::factory()->forVehicleModel($model)->create();

    $visibleCategory = ProductCategory::factory()->create(['title' => 'Category with public alternative']);
    $visibleProduct = Product::factory()->forCategory($visibleCategory)->create();
    $hiddenDefault = ProductVariant::factory()->forProduct($visibleProduct)->default()->create();
    ProductVariant::factory()->forProduct($visibleProduct)->create();
    ProductFitment::factory()->forProduct($visibleProduct)->forVehicleGeneration($generation)->create();
    DB::table('product_variants')->where('id', $hiddenDefault->getKey())->update(['is_active' => false]);

    $hiddenCategory = ProductCategory::factory()->create(['title' => 'Category with hidden part type']);
    $inactivePartType = PartType::factory()->forCategory($hiddenCategory)->create();
    $hiddenProduct = Product::factory()->forCategory($hiddenCategory)->forPartType($inactivePartType)->withDefaultVariant()->create();
    ProductFitment::factory()->forProduct($hiddenProduct)->forVehicleGeneration($generation)->create();
    DB::table('part_types')->where('id', $inactivePartType->getKey())->update(['is_active' => false]);

    $this->get(route('catalog.generation', [$make->slug, $model->slug, $generation->slug]))
        ->assertOk()
        ->assertSee($visibleCategory->title)
        ->assertDontSee($hiddenCategory->title);
});

test('select option groups render as real selects backed by the server variant matrix', function (): void {
    $product = Product::factory()->create(['title' => 'Select option product']);
    $group = ProductOptionGroup::factory()->create(['title' => 'Толщина', 'code' => 'thickness', 'input_type' => 'select']);
    $thin = ProductOptionValue::factory()->forGroup($group)->create(['title' => '1 мм']);
    $thick = ProductOptionValue::factory()->forGroup($group)->create(['title' => '2 мм']);
    $default = ProductVariant::factory()->forProduct($product)->default()->create();
    $other = ProductVariant::factory()->forProduct($product)->create();

    foreach ([[$default, $thin], [$other, $thick]] as [$variant, $value]) {
        ProductVariantOptionValue::factory()->create([
            'product_variant_id' => $variant->getKey(),
            'product_option_group_id' => $group->getKey(),
            'product_option_value_id' => $value->getKey(),
        ]);
    }

    $this->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee('class="part-option-select"', false)
        ->assertSee('data-product-option', false)
        ->assertSee('data-variant-matrix', false)
        ->assertSee('1 мм')
        ->assertSee('2 мм');
});

test('multi variant fallback exposes every public server row and selected availability in both directions', function (): void {
    $outFirstProduct = Product::factory()->create(['title' => 'Out first product']);
    $outFirst = ProductVariant::factory()->forProduct($outFirstProduct)->default()->create([
        'title' => 'Нет в наличии',
        'stock_status' => StockStatus::OutOfStock,
        'stock_quantity' => 0,
    ]);
    $inSecond = ProductVariant::factory()->forProduct($outFirstProduct)->create([
        'title' => 'Есть в наличии',
        'stock_status' => StockStatus::InStock,
        'stock_quantity' => 5,
    ]);

    $outFirstResponse = $this->get(route('products.show', $outFirstProduct->slug))->assertOk()
        ->assertSee('data-product-variant-fallback', false)
        ->assertSee('data-variant-matrix', false)
        ->assertSee('data-add-to-cart disabled', false);
    expect(collect($outFirstResponse->viewData('variantMatrix'))->pluck('variant_id')->all())
        ->toBe([$outFirst->getKey(), $inSecond->getKey()]);

    $inFirstProduct = Product::factory()->create(['title' => 'In first product']);
    $inFirst = ProductVariant::factory()->forProduct($inFirstProduct)->default()->create([
        'title' => 'Доступен',
        'stock_status' => StockStatus::InStock,
        'stock_quantity' => 3,
    ]);
    $outSecond = ProductVariant::factory()->forProduct($inFirstProduct)->create([
        'title' => 'Недоступен',
        'stock_status' => StockStatus::OutOfStock,
        'stock_quantity' => 0,
    ]);

    $inFirstResponse = $this->get(route('products.show', $inFirstProduct->slug))->assertOk()
        ->assertSee('max="3"', false)
        ->assertDontSee('data-add-to-cart disabled', false);
    expect(collect($inFirstResponse->viewData('variantMatrix'))->pluck('variant_id')->all())
        ->toBe([$inFirst->getKey(), $outSecond->getKey()]);
});

test('cart rejects forged variants whose selected option value or group is inactive', function (): void {
    $cart = Cart::factory()->create();
    $cookie = [CartManager::COOKIE_NAME => $cart->token];

    $inactiveValueProduct = Product::factory()->create();
    ProductVariant::factory()->forProduct($inactiveValueProduct)->default()->create();
    $valueGroup = ProductOptionGroup::factory()->create();
    $inactiveValue = ProductOptionValue::factory()->forGroup($valueGroup)->create();
    $inactiveValueVariant = ProductVariant::factory()->forProduct($inactiveValueProduct)->create();
    ProductVariantOptionValue::factory()->create([
        'product_variant_id' => $inactiveValueVariant->getKey(),
        'product_option_group_id' => $valueGroup->getKey(),
        'product_option_value_id' => $inactiveValue->getKey(),
    ]);
    DB::table('product_option_values')->where('id', $inactiveValue->getKey())->update(['is_active' => false]);
    $this->get(route('products.show', $inactiveValueProduct->slug))
        ->assertOk()
        ->assertDontSee('"variant_id":'.$inactiveValueVariant->getKey(), false);

    $this->withCookies($cookie)
        ->from(route('products.show', $inactiveValueProduct->slug))
        ->post(route('cart.items.store'), ['product_variant_id' => $inactiveValueVariant->getKey(), 'quantity' => 1])
        ->assertRedirect()
        ->assertSessionHasErrors('product_variant_id');

    $inactiveGroupProduct = Product::factory()->create();
    ProductVariant::factory()->forProduct($inactiveGroupProduct)->default()->create();
    $inactiveGroup = ProductOptionGroup::factory()->create();
    $groupValue = ProductOptionValue::factory()->forGroup($inactiveGroup)->create();
    $inactiveGroupVariant = ProductVariant::factory()->forProduct($inactiveGroupProduct)->create();
    ProductVariantOptionValue::factory()->create([
        'product_variant_id' => $inactiveGroupVariant->getKey(),
        'product_option_group_id' => $inactiveGroup->getKey(),
        'product_option_value_id' => $groupValue->getKey(),
    ]);
    DB::table('product_option_groups')->where('id', $inactiveGroup->getKey())->update(['is_active' => false]);
    $this->get(route('products.show', $inactiveGroupProduct->slug))
        ->assertOk()
        ->assertDontSee('"variant_id":'.$inactiveGroupVariant->getKey(), false);

    $this->withCookies($cookie)
        ->from(route('products.show', $inactiveGroupProduct->slug))
        ->post(route('cart.items.store'), ['product_variant_id' => $inactiveGroupVariant->getKey(), 'quantity' => 1])
        ->assertRedirect()
        ->assertSessionHasErrors('product_variant_id');

    expect($cart->items()->count())->toBe(0);
});

test('preorder selected product remains purchasable and in stock quantity limits the input', function (): void {
    $preOrder = ProductVariant::factory()->default()->create([
        'stock_status' => StockStatus::PreOrder,
        'stock_quantity' => null,
    ]);
    $this->get(route('products.show', $preOrder->product->slug))
        ->assertOk()
        ->assertSee(StockStatus::PreOrder->label())
        ->assertSee('data-add-to-cart', false)
        ->assertDontSee('data-add-to-cart disabled', false);

    $inStock = ProductVariant::factory()->default()->create([
        'stock_status' => StockStatus::InStock,
        'stock_quantity' => 3,
    ]);
    $this->get(route('products.show', $inStock->product->slug))
        ->assertOk()
        ->assertSee('max="3"', false)
        ->assertDontSee('data-add-to-cart disabled', false);
});

test('cart quantity buttons perform repeat plus minus stock ownership remove and clear flows', function (): void {
    $variant = ProductVariant::factory()->default()->create(['price' => 1234, 'stock_status' => StockStatus::InStock, 'stock_quantity' => 3]);
    $cart = Cart::factory()->create();
    $cookie = [CartManager::COOKIE_NAME => $cart->token];

    $this->withCookies($cookie)->post(route('cart.items.store'), ['product_variant_id' => $variant->getKey(), 'quantity' => 1])->assertRedirect(route('cart.show'));
    $this->withCookies($cookie)->post(route('cart.items.store'), ['product_variant_id' => $variant->getKey(), 'quantity' => 1])->assertRedirect(route('cart.show'));
    $item = $cart->items()->firstOrFail();
    expect($item->quantity)->toBe(2)->and($item->price_snapshot)->toBe('1234.00');

    $this->withCookies($cookie)->get(route('cart.show'))->assertOk()
        ->assertSee('cart-item__qty', false)
        ->assertSee('cart-item__qty-value', false)
        ->assertSee('aria-label="Убавить"', false)
        ->assertSee('aria-label="Добавить"', false)
        ->assertDontSee('type="number" name="quantity"', false)
        ->assertDontSee('Обновить');

    $this->withCookies($cookie)->patch(route('cart.items.update', $item), ['quantity' => 3])->assertRedirect(route('cart.show'));
    expect($item->refresh()->quantity)->toBe(3);
    $this->withCookies($cookie)->patch(route('cart.items.update', $item), ['quantity' => 2])->assertRedirect(route('cart.show'));
    expect($item->refresh()->quantity)->toBe(2);
    $this->withCookies($cookie)->patch(route('cart.items.update', $item), ['quantity' => 4])->assertSessionHasErrors('quantity');
    expect($item->refresh()->quantity)->toBe(2);

    $foreignCart = Cart::factory()->create();
    $this->withCookie(CartManager::COOKIE_NAME, $foreignCart->token)
        ->patch(route('cart.items.update', $item), ['quantity' => 1])
        ->assertNotFound();
    expect($item->refresh()->quantity)->toBe(2);

    $this->withCookies($cookie)->delete(route('cart.items.destroy', $item))->assertRedirect(route('cart.show'));
    expect($cart->items()->count())->toBe(0);
    app(CartManager::class)->addItem(commerceCartRequest($cart), $variant->getKey(), 1);
    $this->withCookies($cookie)->delete(route('cart.clear'))->assertRedirect(route('cart.show'));
    expect($cart->items()->count())->toBe(0);
});

test('cart minus is disabled at one and a foreign remove leaves the item unchanged', function (): void {
    $variant = ProductVariant::factory()->default()->create(['stock_quantity' => null]);
    $cart = Cart::factory()->create();
    $item = app(CartManager::class)->addItem(commerceCartRequest($cart), $variant->getKey(), 1);

    $this->withCookie(CartManager::COOKIE_NAME, $cart->token)->get(route('cart.show'))
        ->assertOk()
        ->assertSee('aria-label="Убавить" disabled', false);

    $foreignCart = Cart::factory()->create();
    $this->withCookie(CartManager::COOKIE_NAME, $foreignCart->token)
        ->delete(route('cart.items.destroy', $item))
        ->assertNotFound();
    expect($item->fresh())->not->toBeNull();
});

test('checkout ignores client prices and thanks stays session scoped and snapshot based', function (): void {
    $delivery = DeliveryMethodSetting::factory()->create(['code' => DeliveryMethod::Pickup, 'base_price' => 350, 'price_mode' => DeliveryPriceMode::Fixed, 'is_active' => true]);
    $payment = PaymentMethodSetting::factory()->create(['code' => PaymentMethod::Sbp, 'is_active' => true]);
    $variant = ProductVariant::factory()->default()->create(['price' => 1750, 'stock_quantity' => null]);
    $variant->product->update(['title' => 'Snapshot title']);
    $cart = Cart::factory()->create();
    app(CartManager::class)->addItem(commerceCartRequest($cart), $variant->getKey(), 2);

    $response = $this->withCookie(CartManager::COOKIE_NAME, $cart->token)->post(route('checkout.store'), [
        'customer_name' => 'Snapshot Customer',
        'customer_phone' => '+79990000000',
        'customer_city' => 'Москва',
        'customer_address' => 'Адрес, 1',
        'delivery_method' => $delivery->code->value,
        'payment_method' => $payment->code->value,
        'agree_terms' => '1',
        'price' => 1,
        'subtotal' => 1,
        'delivery_price' => 1,
        'total' => 1,
    ])->assertRedirect();

    $order = Order::query()->firstOrFail();
    expect($order->subtotal)->toBe('3500.00')->and($order->delivery_price)->toBe('350.00')->and($order->total)->toBe('3850.00');
    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    $token = (string) ($query['token'] ?? '');

    $this->get($location)->assertOk()->assertSee('Snapshot title')->assertSee('3 500');
    $variant->product->update(['title' => 'Mutated live title']);
    $variant->update(['price' => 9999]);
    $this->get($location)->assertOk()->assertSee('Snapshot title')->assertDontSee('Mutated live title')->assertDontSee('9 999');
    $this->get(route('checkout.success', ['order' => $order->number, 'token' => 'wrong-token']))->assertNotFound();

    $otherOrder = Order::factory()->create();
    $this->get(route('checkout.success', ['order' => $otherOrder->number, 'token' => $token]))->assertNotFound();
    $this->flushSession();
    $this->get($location)->assertNotFound();
});
