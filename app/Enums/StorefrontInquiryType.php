<?php

namespace App\Enums;

enum StorefrontInquiryType: string
{
    case GeneralConsultation = 'general_consultation';
    case ProductConsultation = 'product_consultation';
    case Partnership = 'partnership';
    case CustomPart = 'custom_part';

    public function label(): string
    {
        return match ($this) {
            self::GeneralConsultation => 'Общая консультация',
            self::ProductConsultation => 'Консультация по товару',
            self::Partnership => 'Сотрудничество',
            self::CustomPart => 'Изготовление детали',
        };
    }

    /** @return list<string> */
    public function allowedSourceCodes(): array
    {
        return match ($this) {
            self::GeneralConsultation => ['faq', 'about', 'home'],
            self::ProductConsultation => ['product'],
            self::Partnership => ['partners'],
            self::CustomPart => ['home'],
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
