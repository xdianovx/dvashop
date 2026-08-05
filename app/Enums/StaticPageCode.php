<?php

namespace App\Enums;

enum StaticPageCode: string
{
    case About = 'about';
    case How = 'how';
    case Payment = 'payment';
    case Faq = 'faq';
    case Partners = 'partners';

    public function label(): string
    {
        return match ($this) {
            self::About => 'О нас',
            self::How => 'Как мы работаем',
            self::Payment => 'Оплата и доставка',
            self::Faq => 'Вопросы и ответы',
            self::Partners => 'Партнёрам',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $code): array => [$code->value => $code->label()])
            ->all();
    }
}
