<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Новый заказ {{ $order->number }}</title>
</head>
<body style="margin:0;background:#eef0f2;color:#202328;font-family:Arial,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:24px 12px;">
    <tr><td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:720px;background:#fff;border:1px solid #d7dbe0;">
            <tr><td style="padding:26px 30px;background:#262b31;color:#fff;">
                <div style="font-size:12px;letter-spacing:1.5px;text-transform:uppercase;color:#f0b64c;">{{ $storeName }} · новый заказ</div>
                <h1 style="margin:7px 0 0;font-size:25px;">{{ $order->number }}</h1>
                <div style="margin-top:7px;color:#cbd0d6;">{{ ($order->placed_at ?? $order->created_at)?->format('d.m.Y H:i') }}</div>
            </td></tr>
            <tr><td style="padding:24px 30px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="6" style="font-size:14px;background:#f5f6f7;">
                    <tr><td width="32%" style="color:#68717b;">Клиент</td><td><strong>{{ $order->customer_name }}</strong></td></tr>
                    <tr><td style="color:#68717b;">Телефон</td><td>{{ $order->customer_phone }}</td></tr>
                    <tr><td style="color:#68717b;">Email</td><td>{{ $order->customer_email ?: '—' }}</td></tr>
                    <tr><td style="color:#68717b;">Город</td><td>{{ $order->customer_city ?: '—' }}</td></tr>
                    <tr><td style="color:#68717b;">Адрес</td><td>{{ $order->customer_address ?: '—' }}</td></tr>
                    <tr><td style="color:#68717b;">Комментарий</td><td>{!! nl2br(e($order->customer_comment ?: '—')) !!}</td></tr>
                    <tr><td style="color:#68717b;">Оплата</td><td>{{ $order->payment_method_title_snapshot ?: 'Не указана' }}</td></tr>
                    <tr><td style="color:#68717b;">Доставка</td><td>{{ $order->delivery_method_title_snapshot ?: 'Не указана' }}</td></tr>
                    <tr><td style="color:#68717b;">Стоимость доставки</td><td>{{ $order->deliveryPriceText() }}</td></tr>
                </table>

                <table role="presentation" width="100%" cellspacing="0" cellpadding="8" style="margin-top:20px;border-collapse:collapse;font-size:14px;">
                    <thead><tr style="background:#e9ebed;text-align:left;">
                        <th>Товар</th><th align="center">Кол-во</th><th align="right">Цена</th><th align="right">Сумма</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($order->items as $item)
                        <tr style="border-bottom:1px solid #e2e5e8;">
                            <td>
                                <strong>{{ $item->title_snapshot }}</strong>
                                @if ($item->optionSummary() !== '')
                                    <div style="margin-top:4px;color:#68717b;">{{ $item->optionSummary() }}</div>
                                @endif
                                @if ($item->sku_snapshot)
                                    <div style="margin-top:3px;color:#858c94;">Арт. {{ $item->sku_snapshot }}</div>
                                @endif
                            </td>
                            <td align="center">{{ $item->quantity }}</td>
                            <td align="right">{{ number_format((float) $item->price_snapshot, 2, ',', ' ') }} ₽</td>
                            <td align="right"><strong>{{ number_format($item->finalLineTotal(), 2, ',', ' ') }} ₽</strong></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                @if ((float) $order->discount_total > 0)<div style="margin-top:12px;text-align:right;">Промокод {{ $order->promo_code_snapshot }}: −{{ number_format((float) $order->discount_total, 2, ',', ' ') }} ₽</div>@endif
                <div style="margin-top:20px;text-align:right;font-size:20px;"><strong>{{ $order->total_is_final ? 'Итого' : 'Сумма товаров (без доставки)' }}: {{ number_format((float) $order->total, 2, ',', ' ') }} ₽</strong></div>
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
