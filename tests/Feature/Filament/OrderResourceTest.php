<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = User::factory()->superAdmin()->create();
    $this->actingAs($this->admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::bootCurrentPanel();
});

test('OrderResource exposes no delete action and denies resource deletion', function () {
    $order = Order::factory()->create();
    $item = OrderItem::factory()->for($order)->create();

    Livewire::test(ListOrders::class)
        ->assertTableActionDoesNotExist('delete', record: $order);
    Livewire::test(EditOrder::class, ['record' => $order->getKey()])
        ->assertActionDoesNotExist(DeleteAction::class);
    Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
        ->assertActionDoesNotExist(DeleteAction::class);

    expect(OrderResource::canDelete($order))->toBeFalse()
        ->and(OrderResource::canDeleteAny())->toBeFalse()
        ->and($this->admin->can('delete', $order))->toBeFalse()
        ->and($order->fresh())->not->toBeNull()
        ->and($item->fresh())->not->toBeNull();
});

test('OrderResource cancels an order through ordinary status editing', function () {
    $order = Order::factory()->create();

    Livewire::test(EditOrder::class, ['record' => $order->getKey()])
        ->fillForm(['status' => OrderStatus::Canceled->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($order->refresh()->status)->toBe(OrderStatus::Canceled);
});

test('OrderResource lists order totals and checkout metadata', function () {
    $order = Order::factory()->create(['total' => 8450]);

    Livewire::test(ListOrders::class)
        ->assertCanSeeTableRecords([$order])
        ->assertTableColumnExists('id')
        ->assertTableColumnExists('total')
        ->assertTableColumnExists('payment_method')
        ->assertTableColumnExists('payment_status')
        ->assertTableColumnExists('delivery_method');
});

test('OrderResource presents promo financial snapshots as read only fields', function (): void {
    $order = Order::factory()->create([
        'promo_code_snapshot' => 'ADMIN10',
        'subtotal' => 1000,
        'discount_total' => 100,
        'delivery_price' => 200,
        'total' => 1100,
    ]);
    OrderItem::factory()->for($order)->create([
        'total_snapshot' => 1000,
        'discount_snapshot' => 100,
        'final_total_snapshot' => 900,
    ]);

    Livewire::test(EditOrder::class, ['record' => $order->getKey()])
        ->assertFormFieldExists('promo_code_snapshot', fn ($field): bool => $field->isDisabled())
        ->assertFormFieldExists('subtotal', fn ($field): bool => $field->isDisabled())
        ->assertFormFieldExists('discount_total', fn ($field): bool => $field->isDisabled())
        ->assertFormFieldExists('delivery_price', fn ($field): bool => $field->isDisabled())
        ->assertFormFieldExists('total', fn ($field): bool => $field->isDisabled())
        ->assertSet('data.promo_code_snapshot', 'ADMIN10')
        ->assertSet('data.discount_total', '100.00');

    Livewire::test(EditOrder::class, ['record' => $order->getKey()])
        ->set('data.promo_code_snapshot', 'FORGED')
        ->set('data.discount_total', 999)
        ->set('data.items.'.($order->items()->firstOrFail()->getKey()).'.discount_snapshot', 999)
        ->fillForm(['manager_comment' => 'История защищена'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($order->refresh()->promo_code_snapshot)->toBe('ADMIN10')
        ->and($order->discount_total)->toBe('100.00')
        ->and($order->items()->firstOrFail()->discount_snapshot)->toBe('100.00');
});

test('OrderResource updates only operational fields and keeps item snapshots readonly', function () {
    $order = Order::factory()->create();
    $item = OrderItem::factory()->for($order)->create([
        'title_snapshot' => 'Арка задняя',
        'options_snapshot' => ['side' => ['group' => 'Сторона', 'value' => 'Правая']],
        'price_snapshot' => 4200,
        'total_snapshot' => 4200,
        'title' => 'Арка задняя',
        'price' => 4200,
        'total' => 4200,
    ]);
    $snapshot = $item->only([
        'title_snapshot',
        'options_snapshot',
        'price_snapshot',
        'total_snapshot',
    ]);

    $component = Livewire::test(EditOrder::class, ['record' => $order->getKey()]);

    $component
        ->assertFormFieldExists('status')
        ->assertFormFieldExists('payment_status')
        ->assertFormFieldExists('manager_comment')
        ->assertFormFieldExists('items', fn (Repeater $field): bool => $field->isDisabled())
        ->assertSet('data.items', function (array $items): bool {
            $item = reset($items);

            return ($item['title_snapshot'] ?? null) === 'Арка задняя'
                && ($item['options_snapshot'] ?? null) === 'Сторона: Правая';
        })
        ->fillForm([
            'status' => OrderStatus::Processing->value,
            'payment_status' => PaymentStatus::Paid->value,
            'manager_comment' => 'Оплата подтверждена',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Processing)
        ->and($order->payment_status)->toBe(PaymentStatus::Paid)
        ->and($order->manager_comment)->toBe('Оплата подтверждена')
        ->and($order->paid_at)->not->toBeNull()
        ->and($item->refresh()->only(array_keys($snapshot)))->toBe($snapshot);
});

test('OrderResource exposes only current and allowed next statuses', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::Completed,
        'payment_status' => PaymentStatus::Paid,
    ]);

    Livewire::test(EditOrder::class, ['record' => $order->getKey()])
        ->assertFormFieldExists('status', fn (Select $field): bool => $field->getOptions() === [
            OrderStatus::Completed->value => OrderStatus::Completed->label(),
        ])
        ->assertFormFieldExists('payment_status', fn (Select $field): bool => $field->getOptions() === [
            PaymentStatus::Paid->value => PaymentStatus::Paid->label(),
            PaymentStatus::Refunded->value => PaymentStatus::Refunded->label(),
        ]);
});

test('forged OrderResource transitions cannot mutate terminal orders', function (): void {
    $paidAt = now()->subDay()->startOfSecond();
    $order = Order::factory()->create([
        'status' => OrderStatus::Completed,
        'payment_status' => PaymentStatus::Refunded,
        'manager_comment' => 'Зафиксировано',
        'paid_at' => $paidAt,
    ]);
    $before = $order->getAttributes();

    Livewire::test(EditOrder::class, ['record' => $order->getKey()])
        ->set('data.status', OrderStatus::Processing->value)
        ->call('save')
        ->assertStatus(200)
        ->assertHasFormErrors(['status']);

    expect($order->fresh()->getAttributes())->toEqual($before);

    Livewire::test(EditOrder::class, ['record' => $order->getKey()])
        ->set('data.payment_status', PaymentStatus::Paid->value)
        ->call('save')
        ->assertStatus(200)
        ->assertHasFormErrors(['payment_status']);

    expect($order->fresh()->getAttributes())->toEqual($before);
});

test('forged disabled OrderResource state does not change checkout data or item snapshots', function (): void {
    $order = Order::factory()->create([
        'customer_name' => 'Покупатель',
        'subtotal' => 4200,
        'delivery_price' => 300,
        'total' => 4500,
    ]);
    $item = OrderItem::factory()->for($order)->create([
        'title_snapshot' => 'Порог левый',
        'options_snapshot' => ['side' => ['group' => 'Сторона', 'value' => 'Левая']],
        'price_snapshot' => 4200,
        'total_snapshot' => 4200,
    ]);
    $orderBefore = $order->getAttributes();
    $itemBefore = $item->getAttributes();

    Livewire::test(EditOrder::class, ['record' => $order->getKey()])
        ->set('data.customer_name', 'Подмена')
        ->set('data.total', 1)
        ->set('data.paid_at', now()->addYear()->toDateTimeString())
        ->set('data.items.'.$item->getKey().'.title_snapshot', 'Подмена снимка')
        ->fillForm(['manager_comment' => 'Допустимое изменение'])
        ->call('save')
        ->assertHasNoFormErrors();

    $freshOrder = $order->fresh();

    expect($freshOrder->manager_comment)->toBe('Допустимое изменение')
        ->and(collect($freshOrder->getAttributes())->except(['manager_comment', 'updated_at'])->all())
        ->toEqual(collect($orderBefore)->except(['manager_comment', 'updated_at'])->all())
        ->and($item->fresh()->getAttributes())->toEqual($itemBefore);
});
