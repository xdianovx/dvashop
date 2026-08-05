<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Orders\OrderOperationsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->orderOperations = app(OrderOperationsService::class);
    $this->orderActor = User::factory()->admin()->create();
});

test('order status machine enforces the complete transition matrix including no ops', function (): void {
    $allowed = [
        OrderStatus::New->value => [OrderStatus::New, OrderStatus::Processing, OrderStatus::Completed, OrderStatus::Canceled],
        OrderStatus::Processing->value => [OrderStatus::Processing, OrderStatus::Completed, OrderStatus::Canceled],
        OrderStatus::Completed->value => [OrderStatus::Completed],
        OrderStatus::Canceled->value => [OrderStatus::Canceled],
    ];

    foreach (OrderStatus::cases() as $from) {
        foreach (OrderStatus::cases() as $to) {
            $order = Order::factory()->create(['status' => $from]);
            $shouldPass = in_array($to, $allowed[$from->value], true);

            if ($shouldPass) {
                $updated = $this->orderOperations->update($this->orderActor, $order, ['status' => $to]);
                expect($updated->status, "{$from->value} -> {$to->value}")->toBe($to);
            } else {
                expect(fn () => $this->orderOperations->update($this->orderActor, $order, ['status' => $to]))
                    ->toThrow(ValidationException::class);
                expect($order->refresh()->status, "{$from->value} -> {$to->value}")->toBe($from);
            }
        }
    }
});

test('payment status machine enforces the complete transition matrix including no ops', function (): void {
    $allowed = [
        PaymentStatus::Pending->value => [PaymentStatus::Pending, PaymentStatus::Paid, PaymentStatus::Failed],
        PaymentStatus::Failed->value => [PaymentStatus::Failed, PaymentStatus::Pending, PaymentStatus::Paid],
        PaymentStatus::Paid->value => [PaymentStatus::Paid, PaymentStatus::Refunded],
        PaymentStatus::Refunded->value => [PaymentStatus::Refunded],
    ];

    foreach (PaymentStatus::cases() as $from) {
        foreach (PaymentStatus::cases() as $to) {
            $order = Order::factory()->create(['payment_status' => $from]);
            $shouldPass = in_array($to, $allowed[$from->value], true);

            if ($shouldPass) {
                $updated = $this->orderOperations->update($this->orderActor, $order, ['payment_status' => $to]);
                expect($updated->payment_status, "{$from->value} -> {$to->value}")->toBe($to);
            } else {
                expect(fn () => $this->orderOperations->update($this->orderActor, $order, ['payment_status' => $to]))
                    ->toThrow(ValidationException::class);
                expect($order->refresh()->payment_status, "{$from->value} -> {$to->value}")->toBe($from);
            }
        }
    }
});

test('order operations reload a locked row so stale models cannot bypass terminal states', function (): void {
    $statusOrder = Order::factory()->create([
        'status' => OrderStatus::New,
        'manager_comment' => 'До изменения',
    ]);
    $staleStatusOrder = $statusOrder->fresh();
    DB::table('orders')->where('id', $statusOrder->getKey())->update(['status' => OrderStatus::Completed->value]);

    expect(fn () => $this->orderOperations->update($this->orderActor, $staleStatusOrder, [
        'status' => OrderStatus::Processing,
        'manager_comment' => 'Обход',
    ]))->toThrow(ValidationException::class);

    expect($statusOrder->refresh()->status)->toBe(OrderStatus::Completed)
        ->and($statusOrder->manager_comment)->toBe('До изменения');

    $paymentOrder = Order::factory()->create(['payment_status' => PaymentStatus::Pending]);
    $stalePaymentOrder = $paymentOrder->fresh();
    DB::table('orders')->where('id', $paymentOrder->getKey())->update(['payment_status' => PaymentStatus::Refunded->value]);

    expect(fn () => $this->orderOperations->update($this->orderActor, $stalePaymentOrder, [
        'payment_status' => PaymentStatus::Paid,
    ]))->toThrow(ValidationException::class);
    expect($paymentOrder->refresh()->payment_status)->toBe(PaymentStatus::Refunded);
});

test('order operations lock and read the order before issuing its update', function (): void {
    $order = Order::factory()->create();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = mb_strtolower($query->sql);
    });

    $this->orderOperations->update($this->orderActor, $order, ['status' => OrderStatus::Processing]);

    $orderSelect = collect($queries)->search(fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, 'from "orders"'));
    $orderUpdate = collect($queries)->search(fn (string $sql): bool => str_starts_with($sql, 'update "orders"'));

    expect($orderSelect)->not->toBeFalse()
        ->and($orderUpdate)->not->toBeFalse()
        ->and($orderSelect)->toBeLessThan($orderUpdate);
});

test('paid at is set once on first paid transition and preserved through refund and no op', function (): void {
    Carbon::setTestNow('2026-08-05 10:15:00');

    try {
        $order = Order::factory()->create(['payment_status' => PaymentStatus::Pending, 'paid_at' => null]);
        $paid = $this->orderOperations->update($this->orderActor, $order, ['payment_status' => PaymentStatus::Paid]);

        expect($paid->paid_at?->format('Y-m-d H:i:s'))->toBe('2026-08-05 10:15:00');

        Carbon::setTestNow('2026-08-05 12:45:00');
        $noOp = $this->orderOperations->update($this->orderActor, $paid, ['payment_status' => PaymentStatus::Paid]);
        $refunded = $this->orderOperations->update($this->orderActor, $noOp, ['payment_status' => PaymentStatus::Refunded]);

        expect($noOp->paid_at?->format('Y-m-d H:i:s'))->toBe('2026-08-05 10:15:00')
            ->and($refunded->paid_at?->format('Y-m-d H:i:s'))->toBe('2026-08-05 10:15:00');
    } finally {
        Carbon::setTestNow();
    }
});

test('forged fields transitions and validation failures preserve order finances customer and snapshots', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::New,
        'payment_status' => PaymentStatus::Pending,
        'customer_name' => 'Иван Иванов',
        'customer_phone' => '+79990000000',
        'subtotal' => 4200,
        'delivery_price' => 300,
        'total' => 4500,
        'manager_comment' => 'Исходный комментарий',
        'paid_at' => null,
    ]);
    $item = OrderItem::factory()->for($order)->create([
        'title_snapshot' => 'Порог левый',
        'sku_snapshot' => 'POROG-L',
        'options_snapshot' => ['side' => ['group' => 'Сторона', 'value' => 'Левая']],
        'price_snapshot' => 4200,
        'total_snapshot' => 4200,
    ]);
    $orderBefore = $order->getAttributes();
    $itemBefore = $item->getAttributes();

    foreach ([
        ['paid_at' => now()],
        ['total' => 1],
        ['customer_name' => 'Подмена'],
        ['delivery_method' => 'post'],
        ['unknown' => true],
    ] as $payload) {
        expect(fn () => $this->orderOperations->update($this->orderActor, $order, $payload))
            ->toThrow(ValidationException::class);
        expect($order->fresh()->getAttributes())->toEqual($orderBefore)
            ->and($item->fresh()->getAttributes())->toEqual($itemBefore);
    }

    expect(fn () => $this->orderOperations->update($this->orderActor, $order, [
        'status' => 'unknown',
        'manager_comment' => 'Не сохранять',
    ]))->toThrow(ValidationException::class);

    expect($order->fresh()->getAttributes())->toEqual($orderBefore)
        ->and($item->fresh()->getAttributes())->toEqual($itemBefore);
});

test('manager comment is trimmed nullable bounded and all authorized order roles may update', function (): void {
    foreach ([
        User::factory()->superAdmin()->create(),
        User::factory()->admin()->create(),
        User::factory()->manager()->create(),
    ] as $actor) {
        $order = Order::factory()->create();
        $updated = $this->orderOperations->update($actor, $order, ['manager_comment' => '  Проверено  ']);
        expect($updated->manager_comment)->toBe('Проверено');

        $cleared = $this->orderOperations->update($actor, $updated, ['manager_comment' => '   ']);
        expect($cleared->manager_comment)->toBeNull();
    }

    $order = Order::factory()->create();
    expect(fn () => $this->orderOperations->update($this->orderActor, $order, [
        'manager_comment' => str_repeat('я', 5001),
    ]))->toThrow(ValidationException::class);
    expect($order->refresh()->manager_comment)->toBeNull();
});

test('forbidden actors cannot call order operations directly', function (User $actor): void {
    $order = Order::factory()->create();

    expect(fn () => $this->orderOperations->update($actor, $order, ['status' => OrderStatus::Processing]))
        ->toThrow(AuthorizationException::class);
    expect($order->refresh()->status)->toBe(OrderStatus::New);
})->with([
    'customer' => fn () => User::factory()->create(),
    'inactive admin' => fn () => User::factory()->admin()->inactive()->create(),
    'blocked manager' => fn () => User::factory()->manager()->blocked()->create(),
]);
