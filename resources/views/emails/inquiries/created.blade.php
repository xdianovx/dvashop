<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Новая заявка</title>
</head>
<body style="margin:0;background:#eef0f2;color:#202328;font-family:Arial,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:24px 12px;">
    <tr><td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:720px;background:#fff;border:1px solid #d7dbe0;">
            <tr><td style="padding:26px 30px;background:#262b31;color:#fff;">
                <div style="font-size:12px;letter-spacing:1.5px;text-transform:uppercase;color:#f0b64c;">Новая заявка</div>
                <h1 style="margin:7px 0 0;font-size:25px;">{{ $inquiry->type->label() }}</h1>
                <div style="margin-top:7px;color:#cbd0d6;">№ {{ $inquiry->getKey() }} · {{ $inquiry->created_at?->format('d.m.Y H:i') }}</div>
            </td></tr>
            <tr><td style="padding:24px 30px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="6" style="font-size:14px;background:#f5f6f7;">
                    <tr><td width="32%" style="color:#68717b;">Имя</td><td><strong>{{ $inquiry->name }}</strong></td></tr>
                    <tr><td style="color:#68717b;">Телефон</td><td>{{ $inquiry->phone }}</td></tr>
                    <tr><td style="color:#68717b;">Email</td><td>{{ $inquiry->email ?: '—' }}</td></tr>
                    <tr><td style="color:#68717b;">Сообщение</td><td>{!! nl2br(e($inquiry->message ?: '—')) !!}</td></tr>
                    <tr><td style="color:#68717b;">Страница</td><td>{{ $inquiry->source_url }}</td></tr>
                    <tr><td style="color:#68717b;">Источник</td><td>{{ $inquiry->source_code }}</td></tr>
                </table>

                @if ($inquiry->product_title_snapshot)
                    <h2 style="margin:24px 0 10px;font-size:18px;">Товар на момент заявки</h2>
                    <table role="presentation" width="100%" cellspacing="0" cellpadding="6" style="font-size:14px;background:#f5f6f7;">
                        <tr><td width="32%" style="color:#68717b;">Товар</td><td><strong>{{ $inquiry->product_title_snapshot }}</strong></td></tr>
                        <tr><td style="color:#68717b;">SKU</td><td>{{ $inquiry->variant_sku_snapshot ?: '—' }}</td></tr>
                        <tr><td style="color:#68717b;">Опции</td><td>{{ $inquiry->optionSummary() ?: '—' }}</td></tr>
                    </table>
                @endif
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
