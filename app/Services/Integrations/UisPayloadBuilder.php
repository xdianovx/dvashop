<?php

namespace App\Services\Integrations;

use App\Models\Order;
use App\Models\StorefrontInquiry;

class UisPayloadBuilder
{
    /** @return array{correlationKey: string, name: string, email: string, phone: string, message: string} */
    public function forInquiry(StorefrontInquiry $inquiry): array
    {
        $message = collect([
            'Тип заявки: '.$inquiry->type->label(),
            'Источник: '.$inquiry->source_code,
            'URL: '.$inquiry->source_url,
            filled($inquiry->message) ? 'Сообщение: '.$inquiry->message : null,
            filled($inquiry->product_title_snapshot) ? 'Товар: '.$inquiry->product_title_snapshot : null,
            filled($inquiry->variant_sku_snapshot) ? 'SKU: '.$inquiry->variant_sku_snapshot : null,
            $inquiry->optionSummary() !== '' ? 'Опции: '.$inquiry->optionSummary() : null,
        ])->filter()->implode("\n");

        return [
            'correlationKey' => $this->correlationKey('inquiry', (string) $inquiry->getKey()),
            'name' => (string) $inquiry->name,
            'email' => (string) ($inquiry->email ?? ''),
            'phone' => (string) $inquiry->phone,
            'message' => $message,
        ];
    }

    /** @return array{correlationKey: string, name: string, email: string, phone: string, message: string} */
    public function forOrder(Order $order): array
    {
        $message = collect([
            'Заказ: '.$order->number,
            filled($order->customer_city) ? 'Город: '.$order->customer_city : null,
            'Способ доставки: '.($order->delivery_method_title_snapshot ?: 'Не указан'),
            'Оплата: '.($order->payment_method_title_snapshot ?: 'Не указана'),
            'Товары: '.$this->money($order->subtotal),
            filled($order->promo_code_snapshot) ? 'Промокод: '.$order->promo_code_snapshot : null,
            (float) $order->discount_total > 0 ? 'Скидка: '.$this->money($order->discount_total) : null,
            'Доставка: '.$order->deliveryPriceText(),
            ($order->total_is_final ? 'Итого: ' : 'Сумма товаров (без доставки): ').$this->money($order->total),
        ])->filter()->implode("\n");

        return [
            'correlationKey' => 'order:'.$order->number,
            'name' => (string) $order->customer_name,
            'email' => (string) ($order->customer_email ?? ''),
            'phone' => (string) $order->customer_phone,
            'message' => $message,
        ];
    }

    private function correlationKey(string $type, string $identifier): string
    {
        return $type.':'.hash_hmac('sha256', $type.':'.$identifier, (string) config('app.key'));
    }

    private function money(float|int|string|null $amount): string
    {
        return number_format((float) $amount, 2, ',', ' ').' ₽';
    }
}
