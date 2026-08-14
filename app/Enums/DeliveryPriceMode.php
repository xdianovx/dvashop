<?php

namespace App\Enums;

enum DeliveryPriceMode: string
{
    case Free = 'free';
    case Fixed = 'fixed';
    case OnRequest = 'on_request';

    public function label(): string
    {
        return match ($this) {
            self::Free => 'Бесплатно',
            self::Fixed => 'Фиксированная стоимость',
            self::OnRequest => 'Стоимость уточняет менеджер',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $mode): array => [$mode->value => $mode->label()])
            ->all();
    }

    public function storefrontPriceText(float|int|string|null $price = null): string
    {
        return match ($this) {
            self::Free => 'Бесплатно',
            self::Fixed => number_format((float) $price, 0, ',', ' ').' ₽',
            self::OnRequest => 'Стоимость уточнит менеджер',
        };
    }

    public function orderDeliveryText(float|int|string|null $price = null): string
    {
        return $this === self::OnRequest
            ? 'Доставка рассчитывается отдельно'
            : $this->storefrontPriceText($price);
    }
}
