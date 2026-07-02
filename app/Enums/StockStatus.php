<?php

namespace App\Enums;

enum StockStatus: string
{
    case InStock = 'in_stock';
    case OutOfStock = 'out_of_stock';
    case PreOrder = 'pre_order';

    public function label(): string
    {
        return match ($this) {
            self::InStock => 'В наличии',
            self::OutOfStock => 'Нет в наличии',
            self::PreOrder => 'Предзаказ',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status): array => [$status->value => $status->label()])
            ->all();
    }
}
