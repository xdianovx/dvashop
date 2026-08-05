<?php

namespace App\Enums;

enum NavigationLinkType: string
{
    case Route = 'route';
    case Url = 'url';

    public function label(): string
    {
        return match ($this) {
            self::Route => 'Маршрут сайта',
            self::Url => 'Внешний URL',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $type): array => [$type->value => $type->label()])
            ->all();
    }
}
