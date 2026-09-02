<?php

use App\Enums\PromoDiscountType;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Services\Promotions\PromoCodePricingResult;
use App\Services\Promotions\PromoCodePricingService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function promoPricingItem(
    float $price,
    int $quantity = 1,
    ?Product $product = null,
    ?float $oldPrice = null,
): CartItem {
    $product ??= Product::factory()->create();

    return CartItem::factory()->forCart(Cart::factory()->create())->create([
        'product_id' => $product->getKey(),
        'product_variant_id' => null,
        'price_snapshot' => $price,
        'old_price_snapshot' => $oldPrice,
        'quantity' => $quantity,
    ]);
}

/** @param array<string, mixed> $attributes */
function pricingPromo(array $attributes = []): PromoCode
{
    return PromoCode::factory()->create($attributes);
}

function pricePromo(PromoCode $promo, CartItem ...$items): PromoCodePricingResult
{
    return app(PromoCodePricingService::class)->calculate($promo, new Collection($items));
}

test('promo code normalization is trim and case insensitive', function (): void {
    expect(PromoCode::normalizeCode(' sale10 '))->toBe('SALE10')
        ->and(PromoCode::normalizeCode('SALE10'))->toBe('SALE10')
        ->and(PromoCode::normalizeCode('Sale10'))->toBe('SALE10');
});

test('percentage fixed cap and clamping use exact integer cents', function (): void {
    $line = promoPricingItem(1000.01);

    $percentage = pricePromo(pricingPromo(['discount_value' => 10]), $line);
    $capped = pricePromo(pricingPromo(['discount_value' => 50, 'max_discount_amount' => 123.45]), $line);
    $fixed = pricePromo(pricingPromo([
        'discount_type' => PromoDiscountType::Fixed,
        'discount_value' => 125.55,
    ]), $line);
    $clamped = pricePromo(pricingPromo([
        'discount_type' => PromoDiscountType::Fixed,
        'discount_value' => 5000,
    ]), $line);

    expect($percentage->discountCents)->toBe(10000)
        ->and($fixed->discountCents)->toBe(12555)
        ->and($capped->discountCents)->toBe(12345)
        ->and($clamped->discountCents)->toBe(100001)
        ->and($clamped->discountCents)->toBeLessThanOrEqual($clamped->eligibleSubtotalCents);
});

test('minimum eligible subtotal is checked against eligible merchandise only', function (): void {
    $eligible = promoPricingItem(999.99);
    $promo = pricingPromo(['minimum_eligible_subtotal' => 1000]);

    expect(pricePromo($promo, $eligible)->valid)->toBeFalse()
        ->and(pricePromo($promo->forceFill(['minimum_eligible_subtotal' => 999.99]), $eligible)->valid)->toBeTrue();
});

test('all catalog and explicit product category and part type targets work with OR semantics', function (): void {
    $category = ProductCategory::factory()->create();
    $partType = PartType::factory()->create(['product_category_id' => $category->getKey()]);
    $explicit = Product::factory()->create();
    $categoryProduct = Product::factory()->forCategory($category)->create(['part_type_id' => null]);
    $partTypeProduct = Product::factory()->forPartType($partType)->create();
    $mismatch = Product::factory()->create();
    $items = [
        promoPricingItem(100, product: $explicit),
        promoPricingItem(200, product: $categoryProduct),
        promoPricingItem(300, product: $partTypeProduct),
        promoPricingItem(400, product: $mismatch),
    ];
    $all = pricingPromo(['discount_value' => 10]);
    $targeted = pricingPromo(['discount_value' => 10, 'applies_to_all' => false]);
    $targeted->products()->attach($explicit);
    $targeted->productCategories()->attach($category);
    $targeted->partTypes()->attach($partType);

    expect(pricePromo($all, ...$items)->eligibleSubtotalCents)->toBe(100000)
        ->and(pricePromo($targeted, ...$items)->eligibleSubtotalCents)->toBe(60000)
        ->and(pricePromo($targeted, $items[3])->valid)->toBeFalse();
});

test('targeted promo without targets has no eligible lines', function (): void {
    $result = pricePromo(pricingPromo(['applies_to_all' => false]), promoPricingItem(500));

    expect($result->valid)->toBeFalse()
        ->and($result->discountCents)->toBe(0);
});

test('sale items are excluded by default and included when configured', function (): void {
    $regular = promoPricingItem(100);
    $sale = promoPricingItem(80, oldPrice: 100);
    $promo = pricingPromo(['discount_value' => 10]);

    $excluded = pricePromo($promo, $regular, $sale);
    $included = pricePromo($promo->forceFill(['allow_sale_items' => true]), $regular, $sale);

    expect($excluded->eligibleSubtotalCents)->toBe(10000)
        ->and($excluded->lineDiscountsCents)->toHaveKey($regular->getKey())
        ->and($excluded->lineDiscountsCents)->not->toHaveKey($sale->getKey())
        ->and($included->eligibleSubtotalCents)->toBe(18000);
});

test('line allocation is deterministic exact and never exceeds a gross line', function (): void {
    $first = promoPricingItem(0.01);
    $second = promoPricingItem(0.02);
    $third = promoPricingItem(0.03);
    $promo = pricingPromo([
        'discount_type' => PromoDiscountType::Fixed,
        'discount_value' => 0.05,
    ]);
    $result = pricePromo($promo, $third, $first, $second);

    expect(array_sum($result->lineDiscountsCents))->toBe($result->discountCents)
        ->and($result->discountCents)->toBe(5);

    foreach ($result->lineDiscountsCents as $itemId => $discount) {
        expect($discount)->toBeLessThanOrEqual($result->eligibleLineTotalsCents[$itemId]);
    }

    expect(pricePromo($promo, $third, $first, $second)->lineDiscountsCents)
        ->toBe($result->lineDiscountsCents);
});

test('schedule active and soft delete states are enforced', function (array $attributes, bool $valid): void {
    $promo = pricingPromo($attributes);

    expect(pricePromo($promo, promoPricingItem(100))->valid)->toBe($valid);
})->with([
    'active' => [[], true],
    'scheduled' => [['starts_at' => '2999-01-01 00:00:00'], false],
    'expired' => [['ends_at' => '2020-01-01 00:00:00'], false],
    'disabled' => [['is_active' => false], false],
]);

test('soft deleted promo is invalid', function (): void {
    $promo = pricingPromo();
    $promo->delete();

    expect(pricePromo($promo, promoPricingItem(100))->valid)->toBeFalse();
});

test('usage limits count only active redemptions and unlimited promo stays valid', function (): void {
    $item = promoPricingItem(100);
    $limited = pricingPromo(['usage_limit' => 1]);
    $unlimited = pricingPromo(['usage_limit' => null]);
    PromoCodeRedemption::factory()->for($limited)->released()->create();

    expect(pricePromo($limited, $item)->valid)->toBeTrue()
        ->and(pricePromo($unlimited, $item)->valid)->toBeTrue();

    PromoCodeRedemption::factory()->for($limited)->create();

    expect(pricePromo($limited->refresh(), $item)->valid)->toBeFalse();
});
