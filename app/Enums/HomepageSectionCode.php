<?php

namespace App\Enums;

enum HomepageSectionCode: string
{
    case Stories = 'stories';
    case VehicleSearch = 'vehicle_search';
    case CategoryCards = 'category_cards';
    case Reviews = 'reviews';
    case AboutMetrics = 'about_metrics';

    public function adminLabel(): string
    {
        return match ($this) {
            self::Stories => 'Сторис',
            self::VehicleSearch => 'Быстрый поиск запчастей',
            self::CategoryCards => 'Витринные категории',
            self::Reviews => 'Отзывы клиентов',
            self::AboutMetrics => 'О компании',
        };
    }
}
