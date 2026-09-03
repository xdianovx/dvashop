<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\Models\PromoCode;
use App\Models\PromoCodeRedemption;
use App\Models\User;
use App\Services\Orders\OrderInventoryService;
use App\Services\Orders\OrderOperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('first cancellation releases quota once while preserving promo and stock snapshots', function (): void {
    $actor = User::factory()->admin()->create();
    $promo = PromoCode::factory()->create(['usage_limit' => 1]);
    $variant = ProductVariant::factory()->create(['stock_quantity' => 3]);
    $order = Order::factory()->create([
        'promo_code_id' => $promo->getKey(),
        'promo_code_snapshot' => 'KEEP10',
        'discount_total' => 100,
        'subtotal' => 1000,
        'total' => 900,
    ]);
    $item = OrderItem::factory()->for($order)->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => 2,
        'stock_was_decremented' => true,
        'stock_restored_at' => null,
        'total_snapshot' => 1000,
        'discount_snapshot' => 100,
        'final_total_snapshot' => 900,
    ]);
    $redemption = PromoCodeRedemption::factory()->for($promo)->for($order)->create();

    $canceled = app(OrderOperationsService::class)->update($actor, $order, ['status' => OrderStatus::Canceled]);

    expect($redemption->refresh()->released_at)->not->toBeNull()
        ->and($variant->refresh()->stock_quantity)->toBe(5)
        ->and($item->refresh()->stock_restored_at)->not->toBeNull()
        ->and($canceled->promo_code_snapshot)->toBe('KEEP10')
        ->and($canceled->discount_total)->toBe('100.00')
        ->and($promo->activeRedemptions()->count())->toBe(0);

    $releasedAt = $redemption->released_at;
    app(OrderOperationsService::class)->update($actor, $canceled, ['status' => OrderStatus::Canceled]);

    expect($redemption->refresh()->released_at->equalTo($releasedAt))->toBeTrue()
        ->and($variant->refresh()->stock_quantity)->toBe(5);
});

test('failure after redemption release rolls back cancellation quota stock and snapshots', function (): void {
    $actor = User::factory()->admin()->create();
    $promo = PromoCode::factory()->create(['usage_limit' => 1]);
    $variant = ProductVariant::factory()->create(['stock_quantity' => 3]);
    $order = Order::factory()->create([
        'promo_code_id' => $promo->getKey(),
        'promo_code_snapshot' => 'ROLLBACK10',
        'subtotal' => 1000,
        'discount_total' => 100,
        'total' => 900,
    ]);
    $item = OrderItem::factory()->for($order)->create([
        'product_variant_id' => $variant->getKey(),
        'quantity' => 2,
        'stock_was_decremented' => true,
        'stock_restored_at' => null,
        'total_snapshot' => 1000,
        'discount_snapshot' => 100,
        'final_total_snapshot' => 900,
    ]);
    $redemption = PromoCodeRedemption::factory()->for($promo)->for($order)->create();
    $inventory = Mockery::mock(OrderInventoryService::class);
    $inventory->shouldReceive('restoreForCancellation')
        ->once()
        ->andReturnUsing(function () use ($redemption): never {
            expect($redemption->fresh()->released_at)->not->toBeNull();

            throw new RuntimeException('restore failed after release');
        });
    $service = new OrderOperationsService($inventory);

    expect(fn () => $service->update($actor, $order, ['status' => OrderStatus::Canceled]))
        ->toThrow(RuntimeException::class, 'restore failed after release');

    expect($order->refresh()->status)->toBe(OrderStatus::New)
        ->and($redemption->refresh()->released_at)->toBeNull()
        ->and($variant->refresh()->stock_quantity)->toBe(3)
        ->and($item->refresh()->stock_restored_at)->toBeNull()
        ->and($order->promo_code_snapshot)->toBe('ROLLBACK10')
        ->and($order->subtotal)->toBe('1000.00')
        ->and($order->discount_total)->toBe('100.00')
        ->and($order->total)->toBe('900.00');
});

test('order recalculation uses historical line snapshots only for old and promo orders', function (): void {
    $old = Order::factory()->create(['delivery_price' => 50]);
    OrderItem::factory()->for($old)->create([
        'quantity' => 1,
        'total_snapshot' => 100,
        'discount_snapshot' => 0,
        'final_total_snapshot' => 100,
    ]);
    $old->recalculateTotals();

    expect($old->refresh()->subtotal)->toBe('100.00')
        ->and($old->discount_total)->toBe('0.00')
        ->and($old->total)->toBe('150.00');

    $promo = PromoCode::factory()->create(['discount_value' => 99]);
    $order = Order::factory()->create(['promo_code_id' => $promo->getKey(), 'delivery_price' => 25]);
    OrderItem::factory()->for($order)->create([
        'quantity' => 1,
        'total_snapshot' => 200,
        'discount_snapshot' => 30,
        'final_total_snapshot' => 170,
    ]);
    $promo->update(['discount_value' => 1]);
    $order->recalculateTotals();

    expect($order->refresh()->subtotal)->toBe('200.00')
        ->and($order->discount_total)->toBe('30.00')
        ->and($order->total)->toBe('195.00');
});
