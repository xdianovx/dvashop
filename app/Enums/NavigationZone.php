<?php

namespace App\Enums;

enum NavigationZone: string
{
    case HeaderTop = 'header_top';
    case HeaderMain = 'header_main';
    case Mobile = 'mobile';
    case FooterAbout = 'footer_about';
    case FooterDocuments = 'footer_documents';

    public function label(): string
    {
        return match ($this) {
            self::HeaderTop => 'Верхнее меню',
            self::HeaderMain => 'Основное меню',
            self::Mobile => 'Мобильное меню',
            self::FooterAbout => 'Подвал — о нас',
            self::FooterDocuments => 'Подвал — документы',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $zone): array => [$zone->value => $zone->label()])
            ->all();
    }
}
