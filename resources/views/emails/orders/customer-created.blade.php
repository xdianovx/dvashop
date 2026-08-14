<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Заказ {{ $order->number }} принят</title>
</head>
<body style="margin:0;background:#f4f4f2;color:#222;font-family:Arial,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f4f2;padding:24px 12px;">
    <tr><td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:680px;background:#fff;border:1px solid #deded8;">
            <tr><td style="padding:28px 32px 20px;border-bottom:4px solid #d99a21;">
                <div style="font-size:12px;letter-spacing:1.5px;text-transform:uppercase;color:#7a756b;">{{ $storeName }} · заказ принят</div>
                <h1 style="margin:8px 0 0;font-size:26px;line-height:1.2;">Заказ {{ $order->number }}</h1>
                <p style="margin:8px 0 0;color:#666;">{{ ($order->placed_at ?? $order->created_at)?->format('d.m.Y H:i') }}</p>
            </td></tr>
            <tr><td style="padding:24px 32px;">
                <p style="margin:0 0 18px;">{{ $order->customer_name }}, спасибо за заказ.</p>
                <table role="presentation" width="100%" cellspacing="0" cellpadding="8" style="border-collapse:collapse;font-size:14px;">
                    <thead><tr style="background:#f2f0eb;text-align:left;">
                        <th>Товар</th><th align="center">Кол-во</th><th align="right">Цена</th><th align="right">Сумма</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($order->items as $item)
                        <tr style="border-bottom:1px solid #eceae4;">
                            <td>
                                <strong>{{ $item->title_snapshot }}</strong>
                                @if ($item->optionSummary() !== '')
                                    <div style="margin-top:4px;color:#716b60;">{{ $item->optionSummary() }}</div>
                                @endif
                                @if ($item->sku_snapshot)
                                    <div style="margin-top:3px;color:#8a857b;">Арт. {{ $item->sku_snapshot }}</div>
                                @endif
                            </td>
                            <td align="center">{{ $item->quantity }}</td>
                            <td align="right">{{ number_format((float) $item->price_snapshot, 2, ',', ' ') }} ₽</td>
                            <td align="right"><strong>{{ number_format($item->lineTotal(), 2, ',', ' ') }} ₽</strong></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                <table role="presentation" width="100%" cellspacing="0" cellpadding="4" style="margin-top:18px;font-size:14px;">
                    <tr><td>Способ оплаты</td><td align="right">{{ $order->payment_method_title_snapshot ?: 'Не указан' }}</td></tr>
                    <tr><td>Способ доставки</td><td align="right">{{ $order->delivery_method_title_snapshot ?: 'Не указан' }}</td></tr>
                    <tr><td>Стоимость доставки</td><td align="right">{{ $order->deliveryPriceText() }}</td></tr>
                    <tr><td style="padding-top:10px;font-size:18px;"><strong>{{ $order->total_is_final ? 'Итого' : 'Сумма товаров (без доставки)' }}</strong></td><td align="right" style="padding-top:10px;font-size:18px;"><strong>{{ number_format((float) $order->total, 2, ',', ' ') }} ₽</strong></td></tr>
                </table>
                <p style="margin:24px 0 0;padding:16px;background:#fff7e7;border-left:4px solid #d99a21;">Мы свяжемся с вами для подтверждения заказа.</p>
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
