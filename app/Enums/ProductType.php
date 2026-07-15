<?php

namespace App\Enums;

enum ProductType: string
{
    case AutoPart = 'auto_part';
    case Generic = 'generic';

    public function label(): string
    {
        return match ($this) {
            self::AutoPart => 'Автодеталь',
            self::Generic => 'Обычный товар',
        };
    }
}
