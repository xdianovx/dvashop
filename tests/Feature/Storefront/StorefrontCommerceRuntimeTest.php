<?php

use App\Enums\CartStatus;
use App\Enums\DeliveryMethod;
use App\Enums\DeliveryPriceMode;
use App\Enums\PaymentMethod;
use App\Enums\ProductStatus;
use App\Enums\StockStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\DeliveryMethodSetting;
use App\Models\Order;
use App\Models\PartType;
use App\Models\PaymentMethodSetting;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductFitment;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionValue;
use App\Models\ProductVariant;
use App\Models\ProductVariantOptionValue;
use App\Models\VehicleGeneration;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use App\Services\CartManager;
use App\Services\CheckoutService;
use App\Services\StorefrontProductAvailability;
use App\ViewModels\ProductCardViewModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    DeliveryMethodSetting::factory()->create([
        'code' => DeliveryMethod::TransportCompany,
        'base_price' => 0,
        'price_mode' => DeliveryPriceMode::OnRequest,
        'is_active' => true,
    ]);
    PaymentMethodSetting::factory()->create([
        'code' => PaymentMethod::Sbp,
        'is_active' => true,
    ]);
});

function prompt5HCartRequest(Cart $cart): Request
{
    return Request::create('/checkout', 'POST', [], [CartManager::COOKIE_NAME => $cart->token]);
}

/** @return array<string, mixed> */
function prompt5HCheckoutData(): array
{
    return [
        'customer_name' => 'Иван Петров',
        'customer_phone' => '+7 999 123-45-67',
        'customer_email' => null,
        'customer_city' => 'Москва',
        'customer_address' => 'Ленинградское шоссе, 1',
        'customer_comment' => null,
        'delivery_method' => DeliveryMethod::TransportCompany->value,
        'payment_method' => PaymentMethod::Sbp->value,
        'agree_terms' => true,
    ];
}

test('commerce runtime migrations expose product and checkout without delivery mode crashes', function (): void {
    $variant = ProductVariant::factory()->default()->create([
        'price' => 2500,
        'stock_quantity' => null,
    ]);

    expect(Schema::hasTable('storefront_inquiries'))->toBeTrue()
        ->and(Schema::hasColumn('delivery_method_settings', 'price_mode'))->toBeTrue()
        ->and(Schema::hasColumn('orders', 'total_is_final'))->toBeTrue();

    $this->get(route('products.show', $variant->product->slug))->assertOk();
    $this->get(route('checkout.show'))->assertOk();
});

test('catalog search returns separate active vehicle result groups and hierarchy urls', function (): void {
    $make = VehicleMake::factory()->create(['title' => 'Acura', 'slug' => 'acura']);
    $integra = VehicleModel::factory()->forMake($make)->create(['title' => 'Integra', 'slug' => 'integra']);
    $mdx = VehicleModel::factory()->forMake($make)->create(['title' => 'MDX', 'slug' => 'mdx']);
    $generation = VehicleGeneration::factory()->forVehicleModel($integra)->create([
        'title' => 'III',
        'slug' => 'iii',
        'years_label' => '1993–2001',
        'body' => 'sedan',
    ]);
    $mdxGeneration = VehicleGeneration::factory()->forVehicleModel($mdx)->create(['title' => 'MDX Public Generation']);
    $integraProduct = Product::factory()->withDefaultVariant()->create(['title' => 'Integra public product']);
    $mdxProduct = Product::factory()->withDefaultVariant()->create(['title' => 'MDX public product']);
    ProductFitment::factory()->forProduct($integraProduct)->forVehicleGeneration($generation)->create();
    ProductFitment::factory()->forProduct($mdxProduct)->forVehicleGeneration($mdxGeneration)->create();
    $inactiveMake = VehicleMake::factory()->create(['title' => 'Hidden Acura']);
    VehicleModel::factory()->forMake($inactiveMake)->create(['title' => 'Integra Hidden']);
    DB::table('vehicle_makes')->where('id', $inactiveMake->getKey())->update(['is_active' => false]);

    foreach (['Acura', 'Integra', 'MDX', '1993'] as $query) {
        $response = $this->get(route('catalog.index', ['q' => $query]))->assertOk();

        expect($response->viewData('vehicleMakes'))->toBeInstanceOf(Collection::class)
            ->and($response->viewData('vehicleModels'))->toBeInstanceOf(Collection::class)
            ->and($response->viewData('vehicleGenerations'))->toBeInstanceOf(Collection::class);
    }

    $modelResponse = $this->get(route('catalog.index', ['q' => 'Integra']))->assertOk();
    $modelResult = $modelResponse->viewData('vehicleModels')->firstWhere('model_title', 'Integra');

    expect($modelResult)->toMatchArray([
        'make_title' => 'Acura',
        'model_title' => 'Integra',
        'url' => route('catalog.model', [$make->slug, $integra->slug]),
        'generation_count' => 1,
    ]);
    $modelResponse->assertDontSee('Integra Hidden');

    $generationResponse = $this->get(route('catalog.index', ['q' => '1993']))->assertOk();
    $generationResult = $generationResponse->viewData('vehicleGenerations')->firstWhere('title', 'III');

    expect($generationResult)->toMatchArray([
        'make_title' => 'Acura',
        'model_title' => 'Integra',
        'title' => 'III',
        'body' => 'sedan',
        'years_label' => '1993–2001',
        'url' => route('catalog.generation', [$make->slug, $integra->slug, $generation->slug]),
    ]);
    expect($this->get(route('catalog.index', ['q' => $mdx->title]))->status())->toBe(200);
});

test('zero price product stays public but uses request price presentation and cannot enter cart', function (): void {
    $variant = ProductVariant::factory()->default()->create([
        'price' => 0,
        'stock_status' => StockStatus::InStock,
        'stock_quantity' => null,
    ]);
    $product = $variant->product;
    $availability = app(StorefrontProductAvailability::class);
    $card = ProductCardViewModel::fromProduct($product);
    $cart = Cart::factory()->create();

    expect($availability->effectivePrice($variant))->toBe(0.0)
        ->and($availability->hasSellablePrice($variant))->toBeFalse()
        ->and($availability->isPurchasable($variant))->toBeFalse()
        ->and($card->priceAvailable)->toBeFalse()
        ->and($card->priceLabel)->toBe('Цена по запросу')
        ->and($card->variantId)->toBeNull();

    $this->get(route('catalog.index', ['q' => $product->title]))
        ->assertOk()
        ->assertSee($product->title)
        ->assertSee('Цена по запросу')
        ->assertDontSee('0 ₽');

    $productResponse = $this->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee('Цена по запросу')
        ->assertSee('Получить консультацию')
        ->assertSee('data-add-to-cart disabled', false);
    $productResponse->assertViewHas('variantMatrix', fn (array $matrix): bool => $matrix[0]['price_available'] === false
        && $matrix[0]['purchasable'] === false
        && $matrix[0]['price_label'] === 'Цена по запросу');

    expect(fn () => app(CartManager::class)->addItem(prompt5HCartRequest($cart), $variant->getKey()))
        ->toThrow(ValidationException::class, CartManager::PRICE_UNAVAILABLE_MESSAGE);
    expect($cart->items()->count())->toBe(0);
});

test('server variant matrix switches purchase authority between zero and positive prices', function (): void {
    $product = Product::factory()->create(['price' => 0]);
    $zero = ProductVariant::factory()->forProduct($product)->default()->create([
        'price' => 0,
        'stock_quantity' => null,
    ]);
    $positive = ProductVariant::factory()->forProduct($product)->create([
        'price' => 12500,
        'stock_status' => StockStatus::PreOrder,
        'stock_quantity' => null,
    ]);

    $response = $this->get(route('products.show', $product->slug))->assertOk();
    $matrix = collect($response->viewData('variantMatrix'))->keyBy('variant_id');

    expect($matrix[$zero->getKey()])->toMatchArray([
        'price_available' => false,
        'price_label' => 'Цена по запросу',
        'purchasable' => false,
    ])->and($matrix[$positive->getKey()])->toMatchArray([
        'price_available' => true,
        'price_label' => '12 500 руб.',
        'purchasable' => true,
    ]);
});

test('positive in stock and preorder variants remain purchasable', function (): void {
    $inStock = ProductVariant::factory()->default()->create([
        'price' => 1200,
        'stock_status' => StockStatus::InStock,
        'stock_quantity' => 2,
    ]);
    $preOrder = ProductVariant::factory()->default()->create([
        'price' => 3400,
        'stock_status' => StockStatus::PreOrder,
        'stock_quantity' => null,
    ]);
    $availability = app(StorefrontProductAvailability::class);

    expect($availability->isPurchasable($inStock))->toBeTrue()
        ->and($availability->isPurchasable($preOrder))->toBeTrue()
        ->and(ProductCardViewModel::fromProduct($inStock->product)->variantId)->toBe($inStock->getKey())
        ->and(ProductCardViewModel::fromProduct($preOrder->product)->variantId)->toBe($preOrder->getKey());
});

test('old zero snapshot blocks checkout stock and order while cart remains removable', function (): void {
    $variant = ProductVariant::factory()->default()->create([
        'price' => 2500,
        'stock_status' => StockStatus::InStock,
        'stock_quantity' => 5,
    ]);
    $cart = Cart::factory()->create();
    CartItem::query()->create([
        'cart_id' => $cart->getKey(),
        'product_id' => $variant->product_id,
        'product_variant_id' => $variant->getKey(),
        'quantity' => 2,
        'sku_snapshot' => $variant->sku,
        'price_snapshot' => 0,
        'title_snapshot' => $variant->product->title,
        'options_snapshot' => [],
        'image_snapshot' => '/img/placeholders/image.svg',
    ]);

    $this->withCookie(CartManager::COOKIE_NAME, $cart->token)
        ->get(route('cart.show'))
        ->assertOk()
        ->assertSee('Для одного или нескольких товаров требуется уточнить цену.')
        ->assertSee('Цена по запросу')
        ->assertSee('Удалить')
        ->assertDontSee('cart-summary__checkout', false)
        ->assertDontSee('Итого 0 ₽');

    expect(fn () => app(CheckoutService::class)->createOrderFromCart(
        prompt5HCartRequest($cart),
        prompt5HCheckoutData(),
    ))->toThrow(ValidationException::class, CartManager::PRICE_UNAVAILABLE_MESSAGE);

    expect(Order::query()->count())->toBe(0)
        ->and($cart->refresh()->status)->toBe(CartStatus::Active)
        ->and($variant->refresh()->stock_quantity)->toBe(5);
});

test('checkout rejects an existing variant after its public lifecycle is deactivated', function (string $target): void {
    $category = ProductCategory::factory()->create();
    $partType = PartType::factory()->forCategory($category)->create();
    $product = Product::factory()->forCategory($category)->forPartType($partType)->create();
    $variant = ProductVariant::factory()->forProduct($product)->default()->create([
        'price' => 2500,
        'stock_status' => StockStatus::InStock,
        'stock_quantity' => 5,
    ]);
    $group = ProductOptionGroup::factory()->create();
    $value = ProductOptionValue::factory()->forGroup($group)->create();
    ProductVariantOptionValue::factory()->create([
        'product_variant_id' => $variant->getKey(),
        'product_option_group_id' => $group->getKey(),
        'product_option_value_id' => $value->getKey(),
    ]);
    $cart = Cart::factory()->create();
    app(CartManager::class)->addItem(prompt5HCartRequest($cart), $variant->getKey());

    match ($target) {
        'variant inactive' => DB::table('product_variants')->where('id', $variant->getKey())->update(['is_active' => false]),
        'product archived' => DB::table('products')->where('id', $product->getKey())->update(['status' => ProductStatus::Archived->value]),
        'product deleted' => DB::table('products')->where('id', $product->getKey())->update(['deleted_at' => now()]),
        'category inactive' => DB::table('product_categories')->where('id', $category->getKey())->update(['is_active' => false]),
        'category deleted' => DB::table('product_categories')->where('id', $category->getKey())->update(['deleted_at' => now()]),
        'part type inactive' => DB::table('part_types')->where('id', $partType->getKey())->update(['is_active' => false]),
        'part type deleted' => DB::table('part_types')->where('id', $partType->getKey())->update(['deleted_at' => now()]),
        'option value inactive' => DB::table('product_option_values')->where('id', $value->getKey())->update(['is_active' => false]),
        'option group inactive' => DB::table('product_option_groups')->where('id', $group->getKey())->update(['is_active' => false]),
    };

    expect(fn () => app(CheckoutService::class)->createOrderFromCart(
        prompt5HCartRequest($cart),
        prompt5HCheckoutData(),
    ))->toThrow(ValidationException::class, 'больше недоступен');

    expect(Order::query()->count())->toBe(0)
        ->and($cart->refresh()->status)->toBe(CartStatus::Active)
        ->and($variant->refresh()->stock_quantity)->toBe(5);
})->with([
    'variant inactive',
    'product archived',
    'product deleted',
    'category inactive',
    'category deleted',
    'part type inactive',
    'part type deleted',
    'option value inactive',
    'option group inactive',
]);
