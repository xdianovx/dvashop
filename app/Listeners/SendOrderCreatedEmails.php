<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Mail\CustomerOrderCreatedMail;
use App\Mail\ManagerOrderCreatedMail;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendOrderCreatedEmails implements ShouldQueueAfterCommit
{
    public int $tries = 1;

    public function handle(OrderCreated $event): void
    {
        $order = $event->order->loadMissing('items');

        if (filled($order->customer_email)) {
            Mail::to($order->customer_email)->send(new CustomerOrderCreatedMail($order));
        }

        $managerEmail = trim((string) config('shop.orders_manager_email'));

        if ($managerEmail === '') {
            Log::warning('Order manager email is not configured; notification was skipped.', [
                'order_id' => $order->getKey(),
                'order_number' => $order->number,
            ]);

            return;
        }

        Mail::to($managerEmail)->send(new ManagerOrderCreatedMail($order));
    }

    public function failed(OrderCreated $event, Throwable $exception): void
    {
        Log::error('Queued order email notification failed.', [
            'order_id' => $event->order->getKey(),
            'order_number' => $event->order->number,
            'exception' => $exception->getMessage(),
        ]);
    }
}
