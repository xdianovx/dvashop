<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Mail\CustomerOrderCreatedMail;
use App\Models\Order;
use App\Services\Orders\OrderNotificationSettings;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendCustomerOrderEmail implements ShouldQueueAfterCommit
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(private readonly OrderNotificationSettings $settings) {}

    public function handle(OrderCreated $event): void
    {
        if (! config('shop.orders.customer_email_enabled')) {
            return;
        }

        $order = Order::query()->with('items')->findOrFail($event->order->getKey());

        if ($order->customer_email_sent_at !== null || blank($order->customer_email)) {
            return;
        }

        Mail::to($order->customer_email)->send(
            new CustomerOrderCreatedMail($order, $this->settings->storeName()),
        );

        $order->forceFill(['customer_email_sent_at' => now()])->save();
    }

    public function failed(OrderCreated $event, Throwable $exception): void
    {
        Log::error('Queued customer order email failed.', [
            'order_id' => $event->order->getKey(),
            'order_number' => $event->order->number,
            'exception' => $exception->getMessage(),
        ]);
    }
}
