<?php

namespace App\Enums;

enum HomepageCategoryDestination: string
{
    case Catalog = 'catalog';
    case ProductCategory = 'product_category';
    case PartType = 'part_type';

    public function label(): string
    {
        return match ($this) {
            self::Catalog => 'Весь каталог',
            self::ProductCategory => 'Категория магазина',
            self::PartType => 'Тип детали',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $destination): array => [$destination->value => $destination->label()])
            ->all();
    }
}
