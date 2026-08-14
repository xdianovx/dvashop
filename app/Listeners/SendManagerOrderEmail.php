<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Mail\ManagerOrderCreatedMail;
use App\Models\Order;
use App\Services\Orders\OrderNotificationSettings;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendManagerOrderEmail implements ShouldQueueAfterCommit
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(private readonly OrderNotificationSettings $settings) {}

    public function handle(OrderCreated $event): void
    {
        if (! config('shop.orders.manager_email_enabled')) {
            return;
        }

        $order = Order::query()->with('items')->findOrFail($event->order->getKey());

        if ($order->manager_email_sent_at !== null) {
            return;
        }

        $recipient = $this->settings->managerEmail();

        if ($recipient === null) {
            Log::warning('Order manager email is not configured; notification was skipped.', [
                'order_id' => $order->getKey(),
                'order_number' => $order->number,
            ]);

            return;
        }

        Mail::to($recipient)->send(
            new ManagerOrderCreatedMail($order, $this->settings->storeName()),
        );

        $order->forceFill(['manager_email_sent_at' => now()])->save();
    }

    public function failed(OrderCreated $event, Throwable $exception): void
    {
        Log::error('Queued manager order email failed.', [
            'order_id' => $event->order->getKey(),
            'order_number' => $event->order->number,
            'exception' => $exception->getMessage(),
        ]);
    }
}
