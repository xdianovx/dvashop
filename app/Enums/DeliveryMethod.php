<?php

namespace App\Enums;

enum DeliveryMethod: string
{
    case Pickup = 'pickup';
    case Courier = 'courier';
    case TransportCompany = 'transport_company';
    case Post = 'post';

    public function label(): string
    {
        return match ($this) {
            self::Pickup => 'Самовывоз',
            self::Courier => 'Курьер',
            self::TransportCompany => 'Транспортная компания',
            self::Post => 'Почта',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $method): array => [$method->value => $method->label()])
            ->all();
    }
}
