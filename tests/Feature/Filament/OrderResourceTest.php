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
