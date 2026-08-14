<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Integrations\BitrixWebhookClient;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendOrderToBitrix implements ShouldQueueAfterCommit
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(private readonly BitrixWebhookClient $client) {}

    public function handle(OrderCreated $event): void
    {
        if (! config('shop.orders.bitrix_enabled')) {
            return;
        }

        $order = Order::query()->with('items')->findOrFail($event->order->getKey());

        if ($order->bitrix_sent_at !== null) {
            return;
        }

        $entityId = $this->client->addLead(
            $this->fields($order),
            (string) config('shop.bitrix.order_method', 'crm.lead.add'),
        );

        $order->forceFill([
            'bitrix_sent_at' => now(),
            'bitrix_entity_id' => $entityId,
        ])->save();
    }

    public function failed(OrderCreated $event, Throwable $exception): void
    {
        Log::error('Queued order Bitrix delivery failed.', [
            'order_id' => $event->order->getKey(),
            'order_number' => $event->order->number,
            'exception' => $exception->getMessage(),
        ]);
    }

    /**
     * @return array{TITLE:string,NAME:string,PHONE:array<int, array{VALUE:string,VALUE_TYPE:string}>,EMAIL:array<int, array{VALUE:string,VALUE_TYPE:string}>,COMMENTS:string}
     */
    private function fields(Order $order): array
    {
        $items = $order->items
            ->values()
            ->map(fn (OrderItem $item, int $index): string => collect([
                ($index + 1).'. '.$item->title_snapshot,
                filled($item->sku_snapshot) ? 'SKU: '.$item->sku_snapshot : null,
                $item->optionSummary() !== '' ? 'Опции: '.$item->optionSummary() : null,
                'Количество: '.$item->quantity,
                'Цена: '.$this->money($item->price_snapshot),
                'Сумма: '.$this->money($item->lineTotal()),
            ])->filter()->implode("\n"))
            ->implode("\n\n");

        return [
            'TITLE' => 'Заказ '.$order->number,
            'NAME' => $order->customer_name,
            'PHONE' => [['VALUE' => $order->customer_phone, 'VALUE_TYPE' => 'WORK']],
            'EMAIL' => filled($order->customer_email)
                ? [['VALUE' => $order->customer_email, 'VALUE_TYPE' => 'WORK']]
                : [],
            'COMMENTS' => collect([
                'Номер заказа: '.$order->number,
                'Оформлен: '.($order->placed_at ?? $order->created_at)?->format('d.m.Y H:i'),
                'Клиент: '.$order->customer_name,
                'Телефон: '.$order->customer_phone,
                filled($order->customer_email) ? 'Email: '.$order->customer_email : null,
                filled($order->customer_city) ? 'Город: '.$order->customer_city : null,
                filled($order->customer_address) ? 'Адрес: '.$order->customer_address : null,
                filled($order->customer_comment) ? 'Комментарий: '.$order->customer_comment : null,
                "Товары:\n".$items,
                'Товары: '.$this->money($order->subtotal),
                'Доставка: '.($order->delivery_method_title_snapshot ?: 'Не указана'),
                filled($order->delivery_method_description_snapshot)
                    ? 'Описание доставки: '.$order->delivery_method_description_snapshot
                    : null,
                'Код доставки: '.$order->delivery_method->value,
                'Стоимость доставки: '.$order->deliveryPriceText(),
                'Оплата: '.($order->payment_method_title_snapshot ?: 'Не указана'),
                filled($order->payment_method_description_snapshot)
                    ? 'Описание оплаты: '.$order->payment_method_description_snapshot
                    : null,
                ($order->total_is_final ? 'Итого: ' : 'Сумма товаров (без доставки): ').$this->money($order->total),
            ])->filter()->implode("\n"),
        ];
    }

    private function money(float|int|string|null $amount): string
    {
        return number_format((float) $amount, 2, ',', ' ').' ₽';
    }
}
