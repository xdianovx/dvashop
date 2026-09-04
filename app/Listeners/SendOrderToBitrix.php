<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Integrations\BitrixWebhookClient;
use App\Services\Promotions\PromoCodePricingService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class SendOrderToBitrix implements ShouldQueueAfterCommit
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(
        private readonly BitrixWebhookClient $client,
        private readonly PromoCodePricingService $pricing,
    ) {}

    public function handle(OrderCreated $event): void
    {
        if (! config('shop.orders.bitrix_enabled')) {
            return;
        }

        // Longer than both HTTP timeouts; serialize duplicate deliveries per order.
        $lock = Cache::lock('bitrix-order:'.$event->order->getKey(), 60);

        if (! $lock->get()) {
            throw new LockTimeoutException('Bitrix order delivery is already in progress.');
        }

        try {
            // Re-read after acquiring the lock, not from the serialized event.
            $order = Order::query()->with('items')->findOrFail($event->order->getKey());

            if ($order->bitrix_sent_at !== null) {
                return;
            }

            $rows = $this->productRows($order);
            $entityId = $order->bitrix_entity_id;

            if ($entityId === null) {
                $entityId = $this->client->addLead(
                    $this->fields($order),
                    (string) config('shop.bitrix.order_method', 'crm.lead.add'),
                );

                // Keep the remote ID even if productrows.set fails; do not roll it back.
                $order->forceFill(['bitrix_entity_id' => $entityId])->save();
            }

            if ($rows !== []) {
                $this->client->setLeadProductRows($entityId, $rows);
            }

            $order->forceFill(['bitrix_sent_at' => now()])->save();
        } finally {
            $lock->release();
        }
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
     * @return array{TITLE:string,NAME:string,PHONE:array<int, array{VALUE:string,VALUE_TYPE:string}>,EMAIL:array<int, array{VALUE:string,VALUE_TYPE:string}>,COMMENTS:string,OPPORTUNITY:string,CURRENCY_ID:string,IS_MANUAL_OPPORTUNITY:string,SOURCE_ID?:string,ASSIGNED_BY_ID?:string}
     */
    private function fields(Order $order): array
    {
        $sourceId = trim((string) config('shop.bitrix.source_id'));
        $responsibleId = trim((string) config('shop.bitrix.responsible_id'));
        $items = $order->items
            ->values()
            ->map(fn (OrderItem $item, int $index): string => collect([
                ($index + 1).'. '.$item->title_snapshot,
                filled($item->sku_snapshot) ? 'SKU: '.$item->sku_snapshot : null,
                $item->optionSummary() !== '' ? 'Опции: '.$item->optionSummary() : null,
                'Количество: '.$item->quantity,
                'Цена: '.$this->money($item->price_snapshot),
                'Сумма до скидки: '.$this->money($item->lineTotal()),
                (float) $item->discount_snapshot > 0 ? 'Скидка: '.$this->money($item->discount_snapshot) : null,
                'Сумма: '.$this->money($item->finalLineTotal()),
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
                filled($order->promo_code_snapshot) ? 'Промокод: '.$order->promo_code_snapshot : null,
                (float) $order->discount_total > 0 ? 'Скидка: '.$this->money($order->discount_total) : null,
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
            'OPPORTUNITY' => (string) $order->total,
            'CURRENCY_ID' => 'RUB',
            // Delivery may be in the lead amount but is deliberately not a product row.
            'IS_MANUAL_OPPORTUNITY' => 'Y',
            ...($sourceId !== '' ? ['SOURCE_ID' => $sourceId] : []),
            ...($responsibleId !== '' ? ['ASSIGNED_BY_ID' => $responsibleId] : []),
        ];
    }

    /** @return list<array{PRODUCT_NAME:string,PRICE:string,QUANTITY:int}> */
    private function productRows(Order $order): array
    {
        $rows = [];

        foreach ($order->items as $item) {
            $quantity = $item->quantity;

            if ($quantity < 1) {
                throw new InvalidArgumentException('Количество товара заказа должно быть положительным.');
            }

            // The non-null final snapshot already includes the authoritative promo allocation.
            $lineCents = $this->pricing->moneyToCents($item->final_total_snapshot);

            if ($lineCents < 0) {
                throw new InvalidArgumentException('Итоговая сумма товара заказа не может быть отрицательной.');
            }

            $name = $item->title_snapshot;
            if (filled($item->sku_snapshot)) {
                $name .= ' — SKU: '.$item->sku_snapshot;
            }

            $unitCents = intdiv($lineCents, $quantity);
            $remainder = $lineCents % $quantity;

            // At most two real-product groups: exact total and quantity, no sub-cent prices.
            $rows[] = [
                'PRODUCT_NAME' => $name,
                'PRICE' => $this->decimalCents($unitCents),
                'QUANTITY' => $quantity - $remainder,
            ];

            if ($remainder > 0) {
                $rows[] = [
                    'PRODUCT_NAME' => $name,
                    'PRICE' => $this->decimalCents($unitCents + 1),
                    'QUANTITY' => $remainder,
                ];
            }
        }

        return $rows;
    }

    private function decimalCents(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private function money(float|int|string|null $amount): string
    {
        return number_format((float) $amount, 2, ',', ' ').' ₽';
    }
}
