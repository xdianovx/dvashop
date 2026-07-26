<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Card = 'card';
    case Sbp = 'sbp';
    case Invoice = 'invoice';
    case CashOnDelivery = 'cash_on_delivery';

    public function label(): string
    {
        return match ($this) {
            self::Card => 'Банковская карта',
            self::Sbp => 'СБП',
            self::Invoice => 'Счёт для юрлица',
            self::CashOnDelivery => 'При получении',
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
