<?php

use App\Enums\PromoDiscountType;
use App\Models\PartType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Models\User;
use App\Services\Promotions\PromoCodeAdminService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/** @param array<string, mixed> $overrides @return array<string, mixed> */
function promoAdminData(array $overrides = []): array
{
    return [
        'code' => 'sale-10',
        'name' => 'Скидка 10%',
        'description' => null,
        'discount_type' => PromoDiscountType::Percentage->value,
        'discount_value' => 10,
        'max_discount_amount' => null,
        'minimum_eligible_subtotal' => null,
        'applies_to_all' => true,
        'allow_sale_items' => false,
        'usage_limit' => null,
        'starts_at' => null,
        'ends_at' => null,
        'is_active' => true,
        'product_ids' => [],
        'product_category_ids' => [],
        'part_type_ids' => [],
        ...$overrides,
    ];
}

function promoAdmin(): User
{
    return User::factory()->admin()->create();
}

test('admin service creates updates normalizes and clears fixed cap', function (): void {
    $service = app(PromoCodeAdminService::class);
    $actor = promoAdmin();
    $promo = $service->create($actor, promoAdminData());

    expect($promo->code)->toBe('SALE-10')
        ->and($promo->name)->toBe('Скидка 10%');

    $updated = $service->update($actor, $promo, promoAdminData([
        'code' => 'fixed-500',
        'name' => '500 рублей',
        'discount_type' => PromoDiscountType::Fixed->value,
        'discount_value' => 500,
        'max_discount_amount' => 100,
    ]));

    expect($updated->code)->toBe('FIXED-500')
        ->and($updated->discount_type)->toBe(PromoDiscountType::Fixed)
        ->and($updated->max_discount_amount)->toBeNull();
});

test('admin service rejects duplicate case invalid code percentage dates and forged fields', function (): void {
    $service = app(PromoCodeAdminService::class);
    $actor = promoAdmin();
    $service->create($actor, promoAdminData(['code' => 'UNIQUE']));

    foreach ([
        promoAdminData(['code' => 'unique']),
        promoAdminData(['code' => 'bad code']),
        promoAdminData(['discount_value' => 100.0001]),
        promoAdminData(['starts_at' => now(), 'ends_at' => now()->subDay()]),
        [...promoAdminData(['code' => 'FORGED']), 'created_at' => now()],
    ] as $data) {
        expect(fn () => $service->create($actor, $data))->toThrow(ValidationException::class);
    }
});

test('fixed discounts accept whole cents and reject fractional cents on create and update', function (): void {
    $service = app(PromoCodeAdminService::class);
    $actor = promoAdmin();

    foreach ([100, 100.99] as $index => $amount) {
        $promo = $service->create($actor, promoAdminData([
            'code' => 'FIXED-VALID-'.$index,
            'discount_type' => PromoDiscountType::Fixed->value,
            'discount_value' => $amount,
        ]));

        expect((float) $promo->discount_value)->toBe((float) $amount);
    }

    foreach ([100.999, 0.001] as $index => $amount) {
        expect(fn () => $service->create($actor, promoAdminData([
            'code' => 'FIXED-INVALID-'.$index,
            'discount_type' => PromoDiscountType::Fixed->value,
            'discount_value' => $amount,
        ])))->toThrow(ValidationException::class, 'не более двух знаков');
    }

    $promo = $service->create($actor, promoAdminData([
        'code' => 'FIXED-UPDATE',
        'discount_type' => PromoDiscountType::Fixed->value,
        'discount_value' => 100,
    ]));
    $updated = $service->update($actor, $promo, promoAdminData([
        'code' => 'FIXED-UPDATE',
        'discount_type' => PromoDiscountType::Fixed->value,
        'discount_value' => 100.99,
    ]));

    expect($updated->discount_value)->toBe('100.9900');

    foreach ([100.999, 0.001] as $amount) {
        expect(fn () => $service->update($actor, $promo, promoAdminData([
            'code' => 'FIXED-UPDATE',
            'discount_type' => PromoDiscountType::Fixed->value,
            'discount_value' => $amount,
        ])))->toThrow(ValidationException::class, 'не более двух знаков');
    }
});

test('target IDs are validated synced with OR semantics and cleared for all catalog', function (): void {
    $service = app(PromoCodeAdminService::class);
    $actor = promoAdmin();
    $product = Product::factory()->create();
    $category = ProductCategory::factory()->create();
    $partType = PartType::factory()->create(['product_category_id' => $category->getKey()]);
    $promo = $service->create($actor, promoAdminData([
        'applies_to_all' => false,
        'product_ids' => [$product->getKey()],
        'product_category_ids' => [$category->getKey()],
        'part_type_ids' => [$partType->getKey()],
    ]));

    expect($promo->products)->toHaveCount(1)
        ->and($promo->productCategories)->toHaveCount(1)
        ->and($promo->partTypes)->toHaveCount(1);

    $all = $service->update($actor, $promo, promoAdminData(['code' => $promo->code]));

    expect($all->products)->toBeEmpty()
        ->and($all->productCategories)->toBeEmpty()
        ->and($all->partTypes)->toBeEmpty();

    expect(fn () => $service->create($actor, promoAdminData([
        'code' => 'MISSING-TARGET',
        'applies_to_all' => false,
        'product_ids' => [999999],
    ])))->toThrow(ValidationException::class);

    expect(fn () => $service->create($actor, promoAdminData([
        'code' => 'EMPTY-TARGET',
        'applies_to_all' => false,
    ])))->toThrow(ValidationException::class);
});

test('admin can archive and restore but force delete is forbidden', function (): void {
    $service = app(PromoCodeAdminService::class);
    $actor = promoAdmin();
    $promo = $service->create($actor, promoAdminData());

    $service->archive($actor, $promo);
    expect($promo->refresh()->trashed())->toBeTrue();

    $service->restore($actor, $promo);
    expect($promo->refresh()->trashed())->toBeFalse()
        ->and(fn () => $promo->forceDelete())->toThrow(ValidationException::class);
});

test('code is immutable after any historical redemption including released', function (): void {
    $service = app(PromoCodeAdminService::class);
    $actor = promoAdmin();
    $promo = $service->create($actor, promoAdminData());
    PromoCodeRedemption::factory()->for($promo)->released()->create();

    expect(fn () => $service->update($actor, $promo, promoAdminData(['code' => 'CHANGED'])))
        ->toThrow(ValidationException::class);
});

test('manager customer inactive and blocked administrators cannot mutate promos', function (User $actor): void {
    expect(fn () => app(PromoCodeAdminService::class)->create($actor, promoAdminData()))
        ->toThrow(AuthorizationException::class);
})->with([
    'manager' => fn () => User::factory()->manager()->create(),
    'customer' => fn () => User::factory()->create(),
    'inactive admin' => fn () => User::factory()->admin()->inactive()->create(),
    'blocked admin' => fn () => User::factory()->admin()->blocked()->create(),
]);

test('database uniqueness remains effective after model normalization', function (): void {
    PromoCode::factory()->create(['code' => 'DB-UNIQUE']);

    expect(fn () => PromoCode::factory()->create(['code' => 'db-unique']))
        ->toThrow(QueryException::class);
});
