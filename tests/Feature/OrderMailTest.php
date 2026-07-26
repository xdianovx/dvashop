<?php

use App\Enums\DeliveryMethod;
use App\Enums\PaymentMethod;
use App\Events\OrderCreated;
use App\Listeners\SendOrderCreatedEmails;
use App\Mail\CustomerOrderCreatedMail;
use App\Mail\ManagerOrderCreatedMail;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function orderForMail(?string $customerEmail = 'customer@example.test'): Order
{
    $order = Order::factory()->create([
        'customer_name' => 'Анна Смирнова',
        'customer_email' => $customerEmail,
        'payment_method' => PaymentMethod::Card,
        'delivery_method' => DeliveryMethod::Courier,
        'total' => 3000,
    ]);
    OrderItem::factory()->for($order)->create([
        'title_snapshot' => 'Порог тестовый',
        'options_snapshot' => ['material' => ['group' => 'Материал', 'value' => 'Оцинковка']],
        'quantity' => 2,
        'price_snapshot' => 1500,
        'total_snapshot' => 3000,
        'title' => 'Порог тестовый',
        'price' => 1500,
        'total' => 3000,
    ]);

    return $order->load('items');
}

test('OrderMail sends customer and manager messages and renders snapshot details', function () {
    Mail::fake();
    config()->set('shop.orders_manager_email', 'manager@example.test');
    $order = orderForMail();

    app(SendOrderCreatedEmails::class)->handle(new OrderCreated($order));

    Mail::assertSent(CustomerOrderCreatedMail::class, function (CustomerOrderCreatedMail $mail): bool {
        $html = $mail->render();

        return $mail->hasTo('customer@example.test')
            && str_contains($html, 'Порог тестовый')
            && str_contains($html, 'Материал: Оцинковка')
            && str_contains($html, '3 000,00');
    });
    Mail::assertSent(ManagerOrderCreatedMail::class, fn (ManagerOrderCreatedMail $mail): bool => $mail->hasTo('manager@example.test'));
});

test('OrderMail skips customer message without email but still notifies manager', function () {
    Mail::fake();
    config()->set('shop.orders_manager_email', 'manager@example.test');

    app(SendOrderCreatedEmails::class)->handle(new OrderCreated(orderForMail(null)));

    Mail::assertNotSent(CustomerOrderCreatedMail::class);
    Mail::assertSent(ManagerOrderCreatedMail::class, fn (ManagerOrderCreatedMail $mail): bool => $mail->hasTo('manager@example.test'));
});

test('OrderMail does not fail when manager email is missing', function () {
    Mail::fake();
    Log::spy();
    config()->set('shop.orders_manager_email', null);

    app(SendOrderCreatedEmails::class)->handle(new OrderCreated(orderForMail()));

    Mail::assertSent(CustomerOrderCreatedMail::class);
    Mail::assertNotSent(ManagerOrderCreatedMail::class);
    Log::shouldHaveReceived('warning')->once();
});

test('OrderMail listener is registered exactly once', function () {
    $listeners = app(Dispatcher::class)->getRawListeners()[OrderCreated::class] ?? [];

    expect($listeners)->toHaveCount(1)
        ->and($listeners[0])->toBe(SendOrderCreatedEmails::class.'@handle');
});

test('OrderMail listener logs the final queued failure with order identity', function () {
    Log::spy();
    $order = orderForMail();
    $exception = new RuntimeException('SMTP unavailable');

    app(SendOrderCreatedEmails::class)->failed(new OrderCreated($order), $exception);

    Log::shouldHaveReceived('error')
        ->once()
        ->with('Queued order email notification failed.', [
            'order_id' => $order->getKey(),
            'order_number' => $order->number,
            'exception' => 'SMTP unavailable',
        ]);
});
