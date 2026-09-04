<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
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

            $rows = config('shop.bitrix.order_product_rows_enabled', false)
                ? $this->productRows($order)
                : [];
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
     * @return array{TITLE:string,NAME:string,PHONE:array<int, array{VALUE:string,VALUE_TYPE:string}>,EMAIL:array<int, array{VALUE:string,VALUE_TYPE:string}>,SOURCE_DESCRIPTION:string,OPPORTUNITY:string,CURRENCY_ID:string,IS_MANUAL_OPPORTUNITY:string,SOURCE_ID?:string,ASSIGNED_BY_ID?:string}
     */
    private function fields(Order $order): array
    {
        $sourceId = trim((string) config('shop.bitrix.source_id'));
        $responsibleId = trim((string) config('shop.bitrix.responsible_id'));
        $items = $order->items
            ->values()
            ->map(function (OrderItem $item, int $index): string {
                $options = $this->additionalOptions($item);

                return 'Товар '.($index + 1)."\n\n".collect([
                    $item->title_snapshot,
                    filled($item->sku_snapshot) ? 'SKU: '.$item->sku_snapshot : null,
                    $options !== '' ? 'Опции: '.$options : null,
                ])->filter()->implode("\n")."\n\n".collect([
                    'Количество: '.$item->quantity,
                    'Цена за ед.: '.$this->money($item->price_snapshot),
                    'Сумма до скидки: '.$this->money($item->lineTotal()),
                    (float) $item->discount_snapshot > 0 ? 'Скидка: '.$this->money($item->discount_snapshot) : null,
                    'Сумма: '.$this->money($item->finalLineTotal()),
                ])->filter()->implode("\n");
            })
            ->implode("\n\n\n");

        return [
            'TITLE' => 'Заказ '.$order->number,
            'NAME' => $order->customer_name,
            'PHONE' => [['VALUE' => $order->customer_phone, 'VALUE_TYPE' => 'WORK']],
            'EMAIL' => filled($order->customer_email)
                ? [['VALUE' => $order->customer_email, 'VALUE_TYPE' => 'WORK']]
                : [],
            'SOURCE_DESCRIPTION' => collect([
                $this->descriptionSection('ЗАКАЗ', [
                    'Номер: '.$order->number,
                    'Оформлен: '.($order->placed_at ?? $order->created_at)?->format('d.m.Y H:i'),
                ]),
                $this->descriptionSection('КЛИЕНТ', [
                    filled($order->customer_name) ? 'Имя: '.$order->customer_name : null,
                    filled($order->customer_phone) ? 'Телефон: '.$order->customer_phone : null,
                    filled($order->customer_email) ? 'Email: '.$order->customer_email : null,
                    filled($order->customer_city) ? 'Город: '.$order->customer_city : null,
                    filled($order->customer_address) ? 'Адрес: '.$order->customer_address : null,
                ]),
                $this->descriptionSection('КОММЕНТАРИЙ КЛИЕНТА', [$order->customer_comment]),
                $this->descriptionSection('ТОВАРЫ', [$items]),
                $this->descriptionSection('ИТОГ', [
                    'Товары до скидки: '.$this->money($order->subtotal),
                    filled($order->promo_code_snapshot) ? 'Промокод: '.$order->promo_code_snapshot : null,
                    (float) $order->discount_total > 0 ? 'Скидка: '.$this->money($order->discount_total) : null,
                    'Сумма товаров: '.$this->money(max(0, (float) $order->subtotal - (float) $order->discount_total)),
                ]),
                $this->descriptionSection('ДОСТАВКА', [
                    'Способ: '.($order->delivery_method_title_snapshot ?: 'Не указана'),
                    filled($order->delivery_method_description_snapshot)
                        ? 'Описание: '.$order->delivery_method_description_snapshot
                        : null,
                    'Код: '.$order->delivery_method->value,
                    'Стоимость: '.$order->deliveryPriceText(),
                ]),
                $this->descriptionSection('ОПЛАТА', [
                    'Способ: '.($order->payment_method_title_snapshot ?: 'Не указана'),
                    filled($order->payment_method_description_snapshot)
                        ? 'Описание: '.$order->payment_method_description_snapshot
                        : null,
                ]),
                $this->descriptionSection($order->total_is_final ? 'ИТОГО К ОПЛАТЕ' : 'СУММА ТОВАРОВ БЕЗ ДОСТАВКИ', [
                    $this->money($order->total),
                ]),
            ])->filter()->implode("\n\n\n"),
            'OPPORTUNITY' => (string) $order->total,
            'CURRENCY_ID' => 'RUB',
            // Delivery may be in the lead amount but is deliberately not a product row.
            'IS_MANUAL_OPPORTUNITY' => 'Y',
            ...($sourceId !== '' ? ['SOURCE_ID' => $sourceId] : []),
            ...($responsibleId !== '' ? ['ASSIGNED_BY_ID' => $responsibleId] : []),
        ];
    }

    /** @param list<string|null> $lines */
    private function descriptionSection(string $title, array $lines): ?string
    {
        $body = collect($lines)->filter(fn (?string $line): bool => filled($line))->implode("\n");

        return $body !== '' ? $title."\n\n".$body : null;
    }

    private function additionalOptions(OrderItem $item): string
    {
        return collect(ProductVariant::optionsWithoutManagementMetadata($item->options_snapshot) ?? [])
            ->map(function (mixed $option, string|int $key): ?string {
                if (is_array($option) && filled($option['value'] ?? null)) {
                    return (string) (($option['group'] ?? null) ?: $key).': '.$option['value'];
                }

                return is_scalar($option) && filled((string) $option) ? $key.': '.$option : null;
            })
            ->filter()
            // Compare complete saved labels, without parsing the title or loading the catalog.
            ->reject(fn (string $option): bool => str_contains($item->title_snapshot, $option.';')
                || str_ends_with($item->title_snapshot, $option))
            ->implode('; ');
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
